<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Notification\Actions\SendBirthdayMessages;
use App\Domain\Notification\Contracts\SmsGateway;
use App\Domain\Notification\DTOs\SmsResult;
use App\Domain\Notification\Enums\MessageConsentPurpose;
use App\Domain\Notification\Models\AutomationSend;
use App\Domain\Notification\Models\MessageConsent;
use App\Domain\Notification\Models\NotificationLog;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Storefront\Account\AccountTestKit;

/*
 * P8 — the customer records their own birthday-message consent and date of birth on the
 * storefront profile. Source `storefront`, no staff recorder; a "no" withdraws (kept, not
 * deleted); another customer's consent is never readable or writable; a withdrawal stops
 * the next send, and a consent without a birth date sends nothing.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => 'https://toymartbd.com', 'services.storefront.revalidate_secret' => '']);
    $this->me = Customer::factory()->create(['name' => 'সাদিয়া ইসলাম', 'phone' => '+8801712345670', 'preferred_language' => 'en']);
    $this->them = Customer::factory()->create(['phone' => '+8801812345670']);
    $this->meToken = AccountTestKit::tokenFor($this->me);
    $this->themToken = AccountTestKit::tokenFor($this->them);
});

function bdayCustomer(string $token): Illuminate\Foundation\Testing\TestCase
{
    app('auth')->forgetGuards(); // two customers in one test: never reuse the resolved guard

    return test()->withToken($token);
}

/** Records every SMS; WhatsApp off. */
function bdaySms(): object
{
    $fake = new class implements SmsGateway
    {
        /** @var list<string> */
        public array $sent = [];

        public function send(string $e164Phone, string $body, string $template): SmsResult
        {
            $this->sent[] = $e164Phone;

            return SmsResult::accepted('bday-'.count($this->sent), 1, Money::of('0.26'));
        }
    };
    app()->bind(App\Domain\Notification\Drivers\NullSmsGateway::class, fn () => $fake);
    config(['notifications.sms.driver' => 'null', 'notifications.whatsapp.enabled' => false]);

    return $fake;
}

it('refuses the consent endpoints without a customer token', function (): void {
    $this->getJson('/api/v1/storefront/account/consents')->assertUnauthorized();
    $this->putJson('/api/v1/storefront/account/consents', ['birthday' => true])->assertUnauthorized();
    expect(MessageConsent::query()->count())->toBe(0);
});

it('starts not consented, and a "no" with nothing given records nothing', function (): void {
    bdayCustomer($this->meToken)->getJson('/api/v1/storefront/account/consents')->assertOk()
        ->assertExactJson(['birthday' => ['consented' => false, 'consented_at' => null, 'withdrawn_at' => null]]);

    bdayCustomer($this->meToken)->putJson('/api/v1/storefront/account/consents', ['birthday' => false])->assertOk()
        ->assertJsonPath('birthday.consented', false)->assertJsonPath('birthday.withdrawn_at', null);
    expect(MessageConsent::query()->count())->toBe(0);
});

it('records an opt-in from the storefront with no staff recorder, and keeps a withdrawal', function (): void {
    Carbon::setTestNow(now()->startOfMinute());
    $given = now()->toDateTimeString();
    bdayCustomer($this->meToken)->putJson('/api/v1/storefront/account/consents', ['birthday' => true])->assertOk()
        ->assertJsonPath('birthday.consented', true)->assertJsonPath('birthday.withdrawn_at', null)
        ->assertJsonMissingPath('birthday.recorded_by')->assertJsonMissingPath('birthday.source');

    $row = MessageConsent::query()->sole();
    expect($row->customer_id)->toBe($this->me->id)
        ->and($row->purpose)->toBe(MessageConsentPurpose::Birthday)
        ->and($row->source)->toBe('storefront')
        ->and($row->recorded_by)->toBeNull()
        ->and($row->consented_at?->toDateTimeString())->toBe($given);

    Carbon::setTestNow(now()->addHours(2));
    $withdrawn = now()->toDateTimeString();
    bdayCustomer($this->meToken)->putJson('/api/v1/storefront/account/consents', ['birthday' => false])->assertOk()
        ->assertJsonPath('birthday.consented', false);
    $row->refresh();
    expect(MessageConsent::query()->count())->toBe(1) // withdrawn, not deleted
        ->and($row->consented)->toBeFalse()
        ->and($row->withdrawn_at?->toDateTimeString())->toBe($withdrawn)
        ->and($row->consented_at?->toDateTimeString())->toBe($given);
    Carbon::setTestNow();
});

it('never lets one customer read or change another customer\'s consent', function (): void {
    bdayCustomer($this->themToken)->putJson('/api/v1/storefront/account/consents', ['birthday' => true])->assertOk();

    // My view is mine only; there is no id in the path to point at theirs.
    bdayCustomer($this->meToken)->getJson('/api/v1/storefront/account/consents')->assertOk()->assertJsonPath('birthday.consented', false);
    bdayCustomer($this->meToken)->getJson("/api/v1/storefront/account/consents?customer_id={$this->them->id}")->assertJsonPath('birthday.consented', false);
    bdayCustomer($this->meToken)->putJson('/api/v1/storefront/account/consents', ['birthday' => false, 'customer_id' => $this->them->id])->assertOk();

    expect(MessageConsent::given($this->them->id, MessageConsentPurpose::Birthday))->toBeTrue()
        ->and(MessageConsent::query()->where('customer_id', $this->me->id)->exists())->toBeFalse();
});

it('validates the consent body', function (): void {
    bdayCustomer($this->meToken)->putJson('/api/v1/storefront/account/consents', [])->assertUnprocessable();
    bdayCustomer($this->meToken)->putJson('/api/v1/storefront/account/consents', ['birthday' => 'maybe'])->assertUnprocessable();
});

it('saves, shows and clears the date of birth on the profile', function (): void {
    bdayCustomer($this->meToken)->patchJson('/api/v1/storefront/account', ['date_of_birth' => '1992-02-29'])->assertOk()
        ->assertJsonPath('date_of_birth', '1992-02-29');
    bdayCustomer($this->meToken)->getJson('/api/v1/storefront/account')->assertJsonPath('date_of_birth', '1992-02-29');

    foreach (['2099-01-01', '1899-12-31', '29/02/1992', '1993-02-29', now()->toDateString()] as $bad) {
        bdayCustomer($this->meToken)->patchJson('/api/v1/storefront/account', ['date_of_birth' => $bad])->assertUnprocessable();
    }
    // Leaving it out leaves it alone; null clears it.
    bdayCustomer($this->meToken)->patchJson('/api/v1/storefront/account', ['name' => 'Sadia'])->assertJsonPath('date_of_birth', '1992-02-29');
    bdayCustomer($this->meToken)->patchJson('/api/v1/storefront/account', ['date_of_birth' => null])->assertJsonPath('date_of_birth', null);
    expect($this->them->refresh()->date_of_birth)->toBeNull();
});

it('stops the next birthday message after a storefront withdrawal', function (): void {
    $sms = bdaySms();
    $this->me->update(['date_of_birth' => '1990-11-13']);
    bdayCustomer($this->meToken)->putJson('/api/v1/storefront/account/consents', ['birthday' => true])->assertOk();
    bdayCustomer($this->meToken)->putJson('/api/v1/storefront/account/consents', ['birthday' => false])->assertOk();

    expect(app(SendBirthdayMessages::class)->handle(Carbon::parse('2026-11-13 04:00:00')))->toBe(['queued' => 0, 'skipped' => 0])
        ->and(AutomationSend::query()->count())->toBe(0)
        ->and(NotificationLog::query()->count())->toBe(0)
        ->and($sms->sent)->toBe([]);

    // Opting in again sends on the next run (same year, never sent).
    bdayCustomer($this->meToken)->putJson('/api/v1/storefront/account/consents', ['birthday' => true])->assertOk();
    expect(app(SendBirthdayMessages::class)->handle(Carbon::parse('2026-11-13 04:30:00')))->toBe(['queued' => 1, 'skipped' => 0])
        ->and($sms->sent)->toBe(['+8801712345670']);
});

it('sends nothing to a consented customer with no date of birth', function (): void {
    $sms = bdaySms();
    bdayCustomer($this->meToken)->putJson('/api/v1/storefront/account/consents', ['birthday' => true])->assertOk();
    expect($this->me->refresh()->date_of_birth)->toBeNull();

    foreach (['2026-11-13 04:00:00', '2027-02-28 04:00:00', '2027-01-01 04:00:00'] as $at) {
        expect(app(SendBirthdayMessages::class)->handle(Carbon::parse($at)))->toBe(['queued' => 0, 'skipped' => 0]);
    }
    expect(AutomationSend::query()->count())->toBe(0)->and($sms->sent)->toBe([]);
});
