<?php

declare(strict_types=1);

use App\Domain\Cart\Models\Cart;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerOtp;
use App\Domain\Customer\Services\OtpCodes;
use App\Providers\ApiServiceProvider;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Storefront\Account\AccountTestKit;

/*
 * Storefront password sign-in, "Create an account" and "Forgot password" (owner decision
 * 2026-11-20): passwords hashed and never returned, one generic sign-in error, every guess
 * rate limited, codes per purpose, an existing account answered with a 409, a guest-checkout
 * record's name never overwritten, sessions signed out on reset and change.
 */

const PW_PHONE = '01712345678';
const PW_E164 = '+8801712345678';
const PW_SECRET = 'rainbow-blocks-42';

beforeEach(function (): void {
    $this->sms = AccountTestKit::fakeSms();
    $this->token = CartTestKit::storefrontToken();
});

function pwApi(string $path, array $body, array $headers = []): Illuminate\Testing\TestResponse
{
    return test()->withToken(test()->token)->withHeaders($headers)->postJson('/api/v1/storefront/auth/'.$path, $body);
}

function pwRegister(string $name = 'সুমাইয়া আক্তার', string $password = PW_SECRET, string $phone = PW_PHONE, array $headers = []): Illuminate\Testing\TestResponse
{
    pwApi('register', ['phone' => $phone])->assertStatus(202);

    return pwApi('register/verify', ['phone' => $phone, 'code' => test()->sms->lastCode(), 'name' => $name, 'password' => $password, 'password_confirmation' => $password], $headers);
}

function pwCustomer(array $attributes = []): Customer
{
    $customer = Customer::factory()->create(['phone' => PW_E164, ...$attributes]);
    if (! array_key_exists('password', $attributes)) {
        $customer->password = PW_SECRET;
        $customer->password_set_at = now();
        $customer->phone_verified_at = now();
        $customer->save();
    }

    return $customer;
}

// ---- Create an account -------------------------------------------------------------------

it('registers with name + phone + password: a register code, then the account is made and signed in', function (): void {
    $response = pwRegister()->assertOk();

    $customer = Customer::query()->sole();
    expect($response->json('is_new_customer'))->toBeTrue()
        ->and($response->json('token'))->toBeString()
        ->and($response->json('customer.name'))->toBe('সুমাইয়া আক্তার')
        ->and($response->json('customer.has_password'))->toBeTrue()
        ->and($customer->phone)->toBe(PW_E164)
        ->and($customer->phone_verified_at)->not->toBeNull()
        ->and($customer->password_set_at)->not->toBeNull()
        ->and(Hash::check(PW_SECRET, (string) $customer->password))->toBeTrue()
        ->and($customer->getAttributes()['password'])->not->toBe(PW_SECRET)
        ->and(CustomerOtp::query()->sole()->purpose)->toBe(OtpCodes::PURPOSE_REGISTER);

    // The password (or its hash) never leaves the API.
    expect($response->getContent())->not->toContain(PW_SECRET)->not->toContain((string) $customer->password)
        ->and($customer->toArray())->not->toHaveKey('password');
});

it('answers 409 phone-already-registered for a phone that has an account, and texts nothing', function (array $attributes): void {
    pwCustomer($attributes);

    pwApi('register', ['phone' => '+880 1712-345678'])->assertStatus(409)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/phone-already-registered')
        ->assertJsonPath('status', 409)
        ->assertHeader('Content-Type', 'application/problem+json');

    expect($this->sms->sent)->toBe([]);
})->with([
    'has a password' => [[]],
    'signed in by SMS code before' => [['password' => null, 'phone_verified_at' => now()]],
]);

it('lets a guest-checkout customer register, keeping their name and orders record', function (): void {
    $guest = Customer::factory()->create(['phone' => PW_E164, 'name' => 'নুসরাত জাহান', 'phone_verified_at' => null]);

    pwRegister('Someone Else')->assertOk()
        ->assertJsonPath('is_new_customer', false)
        ->assertJsonPath('customer.id', $guest->id)
        ->assertJsonPath('customer.name', 'নুসরাত জাহান');

    expect(Customer::query()->count())->toBe(1)
        ->and($guest->refresh()->hasPassword())->toBeTrue();
});

it('gives a nameless guest-checkout record the typed name', function (): void {
    Customer::factory()->create(['phone' => PW_E164, 'name' => '', 'phone_verified_at' => null]);

    pwRegister('Sumaiya Akter')->assertOk()->assertJsonPath('customer.name', 'Sumaiya Akter');
});

it('refuses with 409 when the phone became registered after the code was sent', function (): void {
    pwApi('register', ['phone' => PW_PHONE])->assertStatus(202);
    $code = $this->sms->lastCode();
    pwCustomer();

    pwApi('register/verify', ['phone' => PW_PHONE, 'code' => $code, 'name' => 'Sumaiya', 'password' => 'another-pass-1', 'password_confirmation' => 'another-pass-1'])
        ->assertStatus(409)->assertJsonPath('type', 'https://toymartbd.com/problems/phone-already-registered');

    expect(Hash::check(PW_SECRET, (string) Customer::query()->sole()->password))->toBeTrue();
});

it('validates the name and password on register', function (array $body, string $field): void {
    pwApi('register', ['phone' => PW_PHONE])->assertStatus(202);

    pwApi('register/verify', ['phone' => PW_PHONE, 'code' => $this->sms->lastCode(), 'name' => 'Sumaiya', 'password' => PW_SECRET, 'password_confirmation' => PW_SECRET, ...$body])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', $field);

    expect(Customer::query()->count())->toBe(0);
})->with([
    'short password' => [['password' => 'short1', 'password_confirmation' => 'short1'], 'password'],
    'repeat does not match' => [['password_confirmation' => 'something-else'], 'password'],
    'no name' => [['name' => ''], 'name'],
]);

it('keeps codes to their purpose: a sign-in code cannot register, a register code cannot sign in', function (): void {
    pwApi('otp', ['phone' => PW_PHONE])->assertStatus(202);
    $login = $this->sms->lastCode();
    pwApi('register/verify', ['phone' => PW_PHONE, 'code' => $login, 'name' => 'Sumaiya', 'password' => PW_SECRET, 'password_confirmation' => PW_SECRET])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'code');

    $this->travel(OtpCodes::RESEND_COOLDOWN_SECONDS + 1)->seconds();
    pwApi('register', ['phone' => PW_PHONE])->assertStatus(202);
    pwApi('otp/verify', ['phone' => PW_PHONE, 'code' => $this->sms->lastCode()])->assertUnprocessable();

    expect(Customer::query()->count())->toBe(0);
});

it('rate limits "Create an account" per phone like a code request', function (): void {
    foreach (range(1, ApiServiceProvider::OTP_PER_TEN_MINUTES) as $i) {
        pwApi('register', ['phone' => PW_PHONE])->assertStatus(202);
        $this->travel(OtpCodes::RESEND_COOLDOWN_SECONDS + 1)->seconds();
    }

    pwApi('register', ['phone' => PW_PHONE])->assertStatus(429);
    pwApi('register', ['phone' => '01812345678'])->assertStatus(202);
});

it('rate limits the registered-phone check per IP', function (): void {
    foreach (range(1, ApiServiceProvider::OTP_PER_IP_PER_HOUR) as $i) {
        pwApi('register', ['phone' => sprintf('0171%07d', $i)])->assertStatus(202);
    }

    pwApi('register', ['phone' => '01999999999'])->assertStatus(429);
});

// ---- Password sign-in --------------------------------------------------------------------

it('signs in with phone + password in any accepted spelling', function (): void {
    $customer = pwCustomer();

    $response = pwApi('password', ['phone' => '+880 1712-345678', 'password' => PW_SECRET])->assertOk()
        ->assertJsonPath('is_new_customer', false)
        ->assertJsonPath('customer.id', $customer->id)
        ->assertJsonPath('customer.has_password', true);

    $token = PersonalAccessToken::findToken($response->json('token'));
    expect($token?->abilities)->toBe(['customer']);
});

it('gives one generic error for an unknown phone, no password set, and a wrong password', function (): void {
    Customer::factory()->create(['phone' => '+8801812345678', 'phone_verified_at' => now()]); // signed in by SMS only
    pwCustomer();

    $unknown = pwApi('password', ['phone' => '01912345678', 'password' => PW_SECRET])->assertUnprocessable();
    $noPassword = pwApi('password', ['phone' => '01812345678', 'password' => PW_SECRET])->assertUnprocessable();
    $wrong = pwApi('password', ['phone' => PW_PHONE, 'password' => 'not-the-password'])->assertUnprocessable();

    expect($unknown->json('errors'))->toBe([['field' => 'password', 'message' => __('account.password.invalid')]])
        ->and($noPassword->json('errors'))->toBe($unknown->json('errors'))
        ->and($wrong->json('errors'))->toBe($unknown->json('errors'))
        ->and(PersonalAccessToken::query()->where('tokenable_type', Customer::class)->count())->toBe(0);
});

it('refuses a blocked customer only after the right password', function (): void {
    pwCustomer(['status' => CustomerStatus::Blocked]);

    pwApi('password', ['phone' => PW_PHONE, 'password' => 'wrong-password'])->assertUnprocessable();
    pwApi('password', ['phone' => PW_PHONE, 'password' => PW_SECRET])->assertForbidden();
});

it('rate limits password guesses per phone, wherever they come from', function (): void {
    pwCustomer();

    foreach (range(1, ApiServiceProvider::PASSWORD_LOGIN_PER_PHONE_PER_TEN_MINUTES) as $i) {
        pwApi('password', ['phone' => PW_PHONE, 'password' => 'guess-'.$i.'-xyz'])->assertUnprocessable();
    }

    pwApi('password', ['phone' => PW_PHONE, 'password' => PW_SECRET])->assertStatus(429);
    pwApi('password', ['phone' => '+8801712345678', 'password' => PW_SECRET])->assertStatus(429);
    pwApi('password', ['phone' => '01812345678', 'password' => PW_SECRET])->assertUnprocessable();
});

it('rate limits password guesses per IP across phones', function (): void {
    foreach (range(1, ApiServiceProvider::PASSWORD_LOGIN_PER_IP_PER_HOUR) as $i) {
        pwApi('password', ['phone' => sprintf('0171%07d', $i), 'password' => 'guess-password'])->assertUnprocessable();
    }

    pwApi('password', ['phone' => '01999999999', 'password' => 'guess-password'])->assertStatus(429);
});

it('merges the guest cart on password sign-in', function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    $variant = CartTestKit::variant('500.00', 20);
    $guest = test()->withToken($this->token)->postJson('/api/v1/storefront/cart/items', ['variant_id' => $variant->id, 'quantity' => 2])->json('token');
    $customer = pwCustomer();

    pwApi('password', ['phone' => PW_PHONE, 'password' => PW_SECRET], [CartTestKit::HEADER => $guest])->assertOk()
        ->assertJsonPath('cart.token', $guest)->assertJsonPath('cart.item_count', 2);

    expect(Cart::query()->where('token', $guest)->value('customer_id'))->toBe($customer->id);
});

// ---- Forgot password ---------------------------------------------------------------------

it('resets a forgotten password with a password_reset code, signs out old sessions and signs in', function (): void {
    $customer = pwCustomer();
    $old = AccountTestKit::tokenFor($customer);

    pwApi('password/forgot', ['phone' => PW_PHONE])->assertStatus(202);
    expect(CustomerOtp::query()->latest('id')->first()?->purpose)->toBe(OtpCodes::PURPOSE_PASSWORD_RESET)
        ->and($this->sms->sent[0]['template'])->toBe('otp.login');

    $response = pwApi('password/reset', ['phone' => PW_PHONE, 'code' => $this->sms->lastCode(), 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
        ->assertOk()->assertJsonPath('customer.id', $customer->id);

    expect(PersonalAccessToken::findToken($old))->toBeNull()
        ->and(PersonalAccessToken::findToken($response->json('token')))->not->toBeNull();

    pwApi('password', ['phone' => PW_PHONE, 'password' => PW_SECRET])->assertUnprocessable();
    pwApi('password', ['phone' => PW_PHONE, 'password' => 'brand-new-pass'])->assertOk();
});

it('answers "forgot password" the same for an unknown phone', function (): void {
    pwApi('password/forgot', ['phone' => '01912345678'])->assertStatus(202)
        ->assertExactJson(['expires_in' => OtpCodes::TTL_SECONDS, 'resend_in' => OtpCodes::RESEND_COOLDOWN_SECONDS, 'length' => 6]);
});

it('does not reset with a wrong code, a sign-in code, or after the code is burned', function (): void {
    pwCustomer();
    pwApi('otp', ['phone' => PW_PHONE])->assertStatus(202);
    $login = $this->sms->lastCode();
    pwApi('password/reset', ['phone' => PW_PHONE, 'code' => $login, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'code');

    $this->travel(OtpCodes::RESEND_COOLDOWN_SECONDS + 1)->seconds();
    pwApi('password/forgot', ['phone' => PW_PHONE])->assertStatus(202);
    $code = $this->sms->lastCode();
    $wrong = $code === '000000' ? '111111' : '000000';
    foreach (range(1, OtpCodes::MAX_ATTEMPTS) as $i) {
        pwApi('password/reset', ['phone' => PW_PHONE, 'code' => $wrong, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])->assertUnprocessable();
    }
    pwApi('password/reset', ['phone' => PW_PHONE, 'code' => $code, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])->assertUnprocessable();

    expect(Hash::check(PW_SECRET, (string) Customer::query()->sole()->password))->toBeTrue();
});

it('rate limits "forgot password" codes per phone', function (): void {
    foreach (range(1, ApiServiceProvider::OTP_PER_TEN_MINUTES) as $i) {
        pwApi('password/forgot', ['phone' => PW_PHONE])->assertStatus(202);
        $this->travel(OtpCodes::RESEND_COOLDOWN_SECONDS + 1)->seconds();
    }

    pwApi('password/forgot', ['phone' => PW_PHONE])->assertStatus(429);
});

// ---- Set / change password (signed in) ---------------------------------------------------

it('lets a customer who signed in by SMS set their first password without a current one', function (): void {
    $customer = Customer::factory()->create(['phone' => PW_E164, 'phone_verified_at' => now()]);
    $bearer = AccountTestKit::tokenFor($customer);

    $this->withToken($bearer)->getJson('/api/v1/storefront/account')->assertOk()->assertJsonPath('has_password', false);
    $this->withToken($bearer)->putJson('/api/v1/storefront/account/password', ['password' => PW_SECRET, 'password_confirmation' => PW_SECRET])
        ->assertOk()->assertJsonPath('has_password', true);

    expect(Hash::check(PW_SECRET, (string) $customer->refresh()->password))->toBeTrue();
});

it('needs the current password to change one, and signs out every other session', function (): void {
    $customer = pwCustomer();
    $bearer = AccountTestKit::tokenFor($customer);
    $other = AccountTestKit::tokenFor($customer);

    $this->withToken($bearer)->putJson('/api/v1/storefront/account/password', ['password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'current_password');
    $this->withToken($bearer)->putJson('/api/v1/storefront/account/password', ['current_password' => 'wrong-one', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'current_password');

    $this->withToken($bearer)->putJson('/api/v1/storefront/account/password', ['current_password' => PW_SECRET, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
        ->assertOk();

    expect(PersonalAccessToken::findToken($other))->toBeNull()
        ->and(PersonalAccessToken::findToken($bearer))->not->toBeNull()
        ->and(Hash::check('brand-new-pass', (string) $customer->refresh()->password))->toBeTrue();
});

it('rate limits password changes per customer', function (): void {
    $customer = pwCustomer();
    $bearer = AccountTestKit::tokenFor($customer);

    foreach (range(1, ApiServiceProvider::ACCOUNT_PASSWORD_PER_TEN_MINUTES) as $i) {
        $this->withToken($bearer)->putJson('/api/v1/storefront/account/password', ['current_password' => 'wrong-one', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
            ->assertUnprocessable();
    }

    $this->withToken($bearer)->putJson('/api/v1/storefront/account/password', ['current_password' => PW_SECRET, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
        ->assertStatus(429);
});

it('needs a customer token to set a password', function (): void {
    $this->withToken($this->token)->putJson('/api/v1/storefront/account/password', ['password' => PW_SECRET, 'password_confirmation' => PW_SECRET])
        ->assertUnauthorized();
});
