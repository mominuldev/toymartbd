<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerChild;
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
 * P8 (batch 3) — the customer records their own consent to birthday reminders with offers on the
 * storefront profile, and their children on "My kids". Source `storefront`, no staff recorder; a
 * "no" withdraws (kept, not deleted); another customer's consent or children are never readable
 * or writable; a withdrawal stops the next send, and a consent without children sends nothing.
 * The customer's own date of birth is no longer collected.
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

it('no longer reads or writes the customer\'s own date of birth', function (): void {
    $this->me->forceFill(['date_of_birth' => '1992-02-29'])->save();
    bdayCustomer($this->meToken)->getJson('/api/v1/storefront/account')->assertOk()->assertJsonMissingPath('date_of_birth');
    bdayCustomer($this->meToken)->patchJson('/api/v1/storefront/account', ['name' => 'Sadia', 'date_of_birth' => '1990-01-01'])->assertOk()
        ->assertJsonMissingPath('date_of_birth');
    expect($this->me->refresh()->date_of_birth?->format('Y-m-d'))->toBe('1992-02-29'); // column kept, never written
});

it('adds, lists, edits and deletes the customer\'s own children', function (): void {
    $url = '/api/v1/storefront/account/children';
    bdayCustomer($this->meToken)->getJson($url)->assertOk()->assertExactJson(['data' => []]);

    $id = bdayCustomer($this->meToken)->postJson($url, ['name' => 'আয়ান', 'birth_month' => 3, 'birth_day' => 12, 'birth_year' => 2020])
        ->assertCreated()->assertJsonPath('name', 'আয়ান')->assertJsonPath('birth_year', 2020)->json('id');
    bdayCustomer($this->meToken)->postJson($url, ['name' => null, 'birth_month' => 2, 'birth_day' => 29, 'birth_year' => null])
        ->assertCreated()->assertJsonPath('name', null)->assertJsonPath('age', null);

    bdayCustomer($this->meToken)->getJson($url)->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.birth_month', 2)->assertJsonPath('data.1.birth_month', 3); // birthday order

    bdayCustomer($this->meToken)->patchJson("{$url}/{$id}", ['name' => 'Ayaan', 'birth_month' => 3, 'birth_day' => 13, 'birth_year' => null])
        ->assertOk()->assertJsonPath('name', 'Ayaan')->assertJsonPath('birth_day', 13)->assertJsonPath('birth_year', null);

    bdayCustomer($this->meToken)->deleteJson("{$url}/{$id}")->assertNoContent();
    expect(CustomerChild::query()->whereKey($id)->exists())->toBeFalse() // deleted, not soft-deleted
        ->and(CustomerChild::query()->where('customer_id', $this->me->id)->count())->toBe(1);
});

it('validates a child: real dates only, optional year within 25 years, at most six', function (): void {
    $url = '/api/v1/storefront/account/children';
    $year = (int) App\Support\DhakaDate::todayDate()->year;
    foreach ([
        ['birth_month' => 2, 'birth_day' => 30, 'birth_year' => null],
        ['birth_month' => 2, 'birth_day' => 29, 'birth_year' => 2021],
        ['birth_month' => 13, 'birth_day' => 1, 'birth_year' => null],
        ['birth_month' => 4, 'birth_day' => 31, 'birth_year' => null],
        ['birth_month' => 1, 'birth_day' => 1, 'birth_year' => $year - 26],
        ['birth_month' => 12, 'birth_day' => 31, 'birth_year' => $year + 1],
        ['birth_month' => 1, 'birth_day' => 1, 'birth_year' => null, 'name' => str_repeat('a', 41)],
    ] as $bad) {
        bdayCustomer($this->meToken)->postJson($url, ['name' => null, ...$bad])->assertUnprocessable();
    }
    for ($i = 1; $i <= CustomerChild::MAX_PER_CUSTOMER; $i++) {
        bdayCustomer($this->meToken)->postJson($url, ['name' => "Kid {$i}", 'birth_month' => $i, 'birth_day' => 1, 'birth_year' => null])->assertCreated();
    }
    bdayCustomer($this->meToken)->postJson($url, ['name' => 'One too many', 'birth_month' => 9, 'birth_day' => 1, 'birth_year' => null])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'children');
    expect(CustomerChild::query()->count())->toBe(CustomerChild::MAX_PER_CUSTOMER);
});

it('never lets one customer see, change or delete another customer\'s child', function (): void {
    $theirs = CustomerChild::query()->create(['customer_id' => $this->them->id, 'name' => 'Mehrin', 'birth_month' => 8, 'birth_day' => 4]);
    $url = '/api/v1/storefront/account/children';

    bdayCustomer($this->meToken)->getJson($url)->assertOk()->assertJsonCount(0, 'data');
    bdayCustomer($this->meToken)->patchJson("{$url}/{$theirs->id}", ['name' => 'X', 'birth_month' => 1, 'birth_day' => 1, 'birth_year' => null])->assertNotFound();
    bdayCustomer($this->meToken)->deleteJson("{$url}/{$theirs->id}")->assertNotFound();
    app('auth')->forgetGuards();
    $this->withToken('nope')->getJson($url)->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken('nope')->postJson($url, ['name' => 'X', 'birth_month' => 1, 'birth_day' => 1, 'birth_year' => null])->assertUnauthorized();

    expect($theirs->refresh()->name)->toBe('Mehrin');
});

it('stops the next birthday reminder after a storefront withdrawal', function (): void {
    $sms = bdaySms();
    CustomerChild::query()->create(['customer_id' => $this->me->id, 'name' => 'Ayaan', 'birth_month' => 11, 'birth_day' => 20]);
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

it('sends nothing to a consented customer with no children', function (): void {
    $sms = bdaySms();
    $this->me->forceFill(['date_of_birth' => '1990-11-13'])->save(); // their own birthday is not a reason any more
    bdayCustomer($this->meToken)->putJson('/api/v1/storefront/account/consents', ['birthday' => true])->assertOk();

    foreach (['2026-11-13 04:00:00', '2027-02-28 04:00:00', '2027-01-01 04:00:00'] as $at) {
        expect(app(SendBirthdayMessages::class)->handle(Carbon::parse($at)))->toBe(['queued' => 0, 'skipped' => 0]);
    }
    expect(AutomationSend::query()->count())->toBe(0)->and($sms->sent)->toBe([]);
});
