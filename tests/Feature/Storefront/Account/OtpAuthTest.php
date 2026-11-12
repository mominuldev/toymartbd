<?php

declare(strict_types=1);

use App\Domain\Cart\Models\Cart;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerOtp;
use App\Domain\Customer\Services\OtpCodes;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Storefront\Account\AccountTestKit;

/*
 * P4-STO-11 — customer sign-in by phone + SMS code: OTP rate limited, codes hashed and
 * single-use, guesses capped, guest cart merged on login.
 */

const OTP_PHONE = '01712345678';
const OTP_E164 = '+8801712345678';

beforeEach(function (): void {
    $this->sms = AccountTestKit::fakeSms();
    $this->token = CartTestKit::storefrontToken();
});

function requestOtp(string $phone = OTP_PHONE): Illuminate\Testing\TestResponse
{
    return test()->withToken(test()->token)->postJson('/api/v1/storefront/auth/otp', ['phone' => $phone]);
}

function verifyOtp(string $code, string $phone = OTP_PHONE, array $headers = []): Illuminate\Testing\TestResponse
{
    return test()->withToken(test()->token)->withHeaders($headers)->postJson('/api/v1/storefront/auth/otp/verify', ['phone' => $phone, 'code' => $code]);
}

function wrongCode(string $right): string
{
    return $right === '000000' ? '111111' : '000000';
}

it('needs the storefront server token', function (): void {
    $this->postJson('/api/v1/storefront/auth/otp', ['phone' => OTP_PHONE])->assertUnauthorized();
});

it('texts a 6-digit code over SMS and stores only hashes', function (): void {
    requestOtp('+880 1712-345678')->assertStatus(202)
        ->assertExactJson(['expires_in' => OtpCodes::TTL_SECONDS, 'resend_in' => OtpCodes::RESEND_COOLDOWN_SECONDS, 'length' => 6]);

    expect($this->sms->sent)->toHaveCount(1)
        ->and($this->sms->sent[0]['phone'])->toBe(OTP_E164)
        ->and($this->sms->sent[0]['template'])->toBe('otp.login');

    $code = $this->sms->lastCode();
    $row = CustomerOtp::query()->sole();
    expect(json_encode($row->getAttributes()))->not->toContain($code)->not->toContain('1712345678');
});

it('rejects numbers outside 013–019 (rule 30) without echoing them', function (string $phone): void {
    $response = requestOtp($phone)->assertUnprocessable()->assertJsonPath('errors.0.field', 'phone');
    expect($response->getContent())->not->toContain($phone);
    expect($this->sms->sent)->toBe([]);
})->with(['01112345678', '01212345678', '0171234567', '06012345678']);

it('enforces the resend cooldown with Retry-After', function (): void {
    requestOtp()->assertStatus(202);

    requestOtp()->assertStatus(429)->assertHeader('Retry-After')
        ->assertJsonPath('type', 'https://toymartbd.com/problems/too-many-requests');

    expect($this->sms->sent)->toHaveCount(1);
});

it('rate limits codes per phone: 3 in 10 minutes, wherever they come from', function (): void {
    foreach (range(1, 3) as $i) {
        requestOtp()->assertStatus(202);
        $this->travel(OtpCodes::RESEND_COOLDOWN_SECONDS + 1)->seconds();
    }

    requestOtp()->assertStatus(429);
    // Another spelling of the same number shares the bucket.
    requestOtp('+8801712345678')->assertStatus(429);
    // Another phone is unaffected.
    requestOtp('01812345678')->assertStatus(202);

    expect($this->sms->sent)->toHaveCount(4);
});

it('caps codes per phone per hour in the domain, beyond the route limiter', function (): void {
    foreach (range(1, OtpCodes::MAX_SENDS_PER_HOUR) as $i) {
        requestOtp()->assertStatus(202);
        $this->travel(4)->minutes(); // clears the 3-per-10-minutes route window
    }

    requestOtp()->assertStatus(429)->assertHeader('Retry-After');
});

it('signs in with the right code, creating the customer by E.164 phone', function (): void {
    requestOtp();
    $response = verifyOtp($this->sms->lastCode())->assertOk();

    $customer = Customer::query()->sole();
    expect($customer->phone)->toBe(OTP_E164)
        ->and($customer->phone_verified_at)->not->toBeNull()
        ->and($response->json('is_new_customer'))->toBeTrue()
        ->and($response->json('customer.phone'))->toBe(OTP_PHONE)
        ->and($response->json('token'))->toBeString();

    $token = PersonalAccessToken::findToken($response->json('token'));
    expect($token?->tokenable_type)->toBe(Customer::class)
        ->and($token?->abilities)->toBe(['customer'])
        ->and($token?->expires_at)->not->toBeNull();
});

it('signs an existing (guest-checkout) customer into the same record', function (): void {
    $existing = Customer::factory()->create(['phone' => OTP_E164, 'name' => 'নুসরাত জাহান']);

    requestOtp();
    verifyOtp($this->sms->lastCode())->assertOk()
        ->assertJsonPath('is_new_customer', false)
        ->assertJsonPath('customer.id', $existing->id)
        ->assertJsonPath('customer.name', 'নুসরাত জাহান');

    expect(Customer::query()->count())->toBe(1);
});

it('accepts Bengali digits in the code', function (): void {
    requestOtp();
    $bn = strtr($this->sms->lastCode(), ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯']);

    verifyOtp($bn)->assertOk();
});

it('uses a code once', function (): void {
    requestOtp();
    $code = $this->sms->lastCode();

    verifyOtp($code)->assertOk();
    verifyOtp($code)->assertUnprocessable()->assertJsonPath('errors.0.field', 'code');
});

it('burns the code after 5 wrong guesses, even if the 6th is right', function (): void {
    requestOtp();
    $code = $this->sms->lastCode();

    foreach (range(1, OtpCodes::MAX_ATTEMPTS) as $i) {
        verifyOtp(wrongCode($code))->assertUnprocessable();
    }

    verifyOtp($code)->assertUnprocessable();
    expect(Customer::query()->count())->toBe(0);
});

it('rate limits verification per phone', function (): void {
    foreach (range(1, 10) as $i) {
        verifyOtp('123456')->assertUnprocessable();
    }

    verifyOtp('123456')->assertStatus(429);
});

it('expires a code after five minutes', function (): void {
    requestOtp();
    $code = $this->sms->lastCode();

    $this->travel(OtpCodes::TTL_SECONDS + 1)->seconds();

    verifyOtp($code)->assertUnprocessable();
});

it('only the newest code works after a resend', function (): void {
    requestOtp();
    $first = $this->sms->lastCode();
    $this->travel(OtpCodes::RESEND_COOLDOWN_SECONDS + 1)->seconds();
    requestOtp();
    $second = $this->sms->lastCode();

    if ($first !== $second) {
        verifyOtp($first)->assertUnprocessable();
    }
    verifyOtp($second)->assertOk();
});

it('does not let a code for one phone sign in another', function (): void {
    requestOtp();
    $code = $this->sms->lastCode();

    verifyOtp($code, '01812345678')->assertUnprocessable();
});

it('refuses a blocked customer', function (): void {
    Customer::factory()->create(['phone' => OTP_E164, 'status' => CustomerStatus::Blocked]);

    requestOtp();
    verifyOtp($this->sms->lastCode())->assertForbidden();

    expect(PersonalAccessToken::query()->where('tokenable_type', Customer::class)->count())->toBe(0);
});

it('merges the guest cart into the customer cart on sign-in', function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    $shared = CartTestKit::variant('500.00', 20);
    $guestOnly = CartTestKit::variant('200.00', 20);

    // The customer already has a cart from an earlier visit.
    $customer = Customer::factory()->create(['phone' => OTP_E164]);
    $old = test()->withToken($this->token)->postJson('/api/v1/storefront/cart/items', ['variant_id' => $shared->id, 'quantity' => 1])->json('token');
    Cart::query()->where('token', $old)->update(['customer_id' => $customer->id]);

    $guest = test()->withToken($this->token)->postJson('/api/v1/storefront/cart/items', ['variant_id' => $shared->id, 'quantity' => 2])->json('token');
    test()->withToken($this->token)->withHeader(CartTestKit::HEADER, $guest)
        ->postJson('/api/v1/storefront/cart/items', ['variant_id' => $guestOnly->id, 'quantity' => 1])->assertOk();

    requestOtp();
    $response = verifyOtp($this->sms->lastCode(), headers: [CartTestKit::HEADER => $guest])->assertOk();

    expect($response->json('cart.token'))->toBe($old)
        ->and($response->json('cart.item_count'))->toBe(4);

    // One line per variant, quantities added; the guest cart's token no longer resolves.
    test()->withToken($this->token)->withHeader(CartTestKit::HEADER, $old)->getJson('/api/v1/storefront/cart')
        ->assertOk()->assertJsonCount(2, 'items');
    test()->withToken($this->token)->withHeader(CartTestKit::HEADER, $guest)->getJson('/api/v1/storefront/cart')->assertNotFound();
});

it('hands a lone guest cart to the new customer', function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    $variant = CartTestKit::variant('500.00', 20);
    $guest = test()->withToken($this->token)->postJson('/api/v1/storefront/cart/items', ['variant_id' => $variant->id, 'quantity' => 2])->json('token');

    requestOtp();
    verifyOtp($this->sms->lastCode(), headers: [CartTestKit::HEADER => $guest])->assertOk()
        ->assertJsonPath('cart.token', $guest)->assertJsonPath('cart.item_count', 2);

    expect(Cart::query()->where('token', $guest)->value('customer_id'))->toBe(Customer::query()->sole()->id);
});

it('signs out by revoking the token', function (): void {
    requestOtp();
    $token = verifyOtp($this->sms->lastCode())->json('token');

    $this->withToken($token)->getJson('/api/v1/storefront/account')->assertOk();
    $this->withToken($token)->postJson('/api/v1/storefront/account/logout')->assertNoContent();

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/storefront/account')->assertUnauthorized();
});
