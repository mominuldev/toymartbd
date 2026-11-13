<?php

declare(strict_types=1);

use App\Domain\Customer\Actions\RemoveCustomerChild;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerChild;
use App\Domain\Marketing\Enums\CouponRejection;
use App\Domain\Marketing\Enums\CouponType;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Services\CouponEvaluator;
use App\Domain\Notification\Actions\ReplaceUnchangedDefaultTemplate;
use App\Domain\Notification\Actions\SendBirthdayMessages;
use App\Domain\Notification\Actions\SetMessageConsent;
use App\Domain\Notification\Actions\SetNotificationPreference;
use App\Domain\Notification\Contracts\SmsGateway;
use App\Domain\Notification\DTOs\SmsResult;
use App\Domain\Notification\Enums\AutomationSkip;
use App\Domain\Notification\Enums\MessageConsentPurpose;
use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Models\AutomationSend;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Models\NotificationTemplateVersion;
use App\Domain\Notification\Services\AutomationSettings;
use App\Models\Enums\Role;
use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * P8 batch 3 (owner decision 2026-11-13) — birthday reminders go to the PARENT before each CHILD's
 * birthday: `lead_days` ahead (default 7), from 10:00 Dhaka outside quiet hours, once per child per
 * year (29 February → 28 February), only with the birthday consent. With the offer on, each send
 * carries ONE personal single-use coupon minted through Marketing's contract; a replay mints none.
 */

final class ReminderRecordingSms implements SmsGateway
{
    /** @var list<array{to: string, body: string}> */
    public array $sent = [];

    public function send(string $e164Phone, string $body, string $template): SmsResult
    {
        $this->sent[] = ['to' => $e164Phone, 'body' => $body];

        return SmsResult::accepted('rem-'.count($this->sent), 1, Money::of('0.26'));
    }
}

function reminderSms(): ReminderRecordingSms
{
    $fake = new ReminderRecordingSms;
    app()->bind(App\Domain\Notification\Drivers\NullSmsGateway::class, fn () => $fake);
    config(['notifications.sms.driver' => 'null', 'notifications.whatsapp.enabled' => false]);

    return $fake;
}

/** A consenting parent with one child. */
function parentWithChild(int $month, int $day, ?string $child = 'Ayaan', array $customer = [], bool $consent = true): CustomerChild
{
    $parent = Customer::factory()->create(['name' => 'Rahim Uddin', 'preferred_language' => 'en', ...$customer]);
    if ($consent) {
        app(SetMessageConsent::class)->handle($parent->id, MessageConsentPurpose::Birthday, true, 'storefront', null);
    }

    return CustomerChild::query()->create(['customer_id' => $parent->id, 'name' => $child, 'birth_month' => $month, 'birth_day' => $day]);
}

function offerOn(array $offer = []): void
{
    app(App\Domain\Notification\Actions\UpdateAutomationSettings::class)->handle(3, '21:00', '09:00', null, null, [
        'enabled' => true, 'type' => 'percent', 'value' => '10.00', 'max_discount' => '300.00', 'min_order_amount' => '0.00', 'valid_days' => 14, ...$offer,
    ]);
}

function remind(string $utc): array
{
    return app(SendBirthdayMessages::class)->handle(Carbon::parse($utc));
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => 'https://toymartbd.com', 'services.storefront.revalidate_secret' => '', 'notifications.store_url' => 'toymartbd.com']);
});

it('reminds the parent lead days before each child\'s birthday, from 10:00 Dhaka, once a year per child', function (): void {
    $sms = reminderSms();
    $ayaan = parentWithChild(11, 20, 'Ayaan');
    $mehrin = CustomerChild::query()->create(['customer_id' => $ayaan->customer_id, 'name' => 'Mehrin', 'birth_month' => 11, 'birth_day' => 22]);
    $later = CustomerChild::query()->create(['customer_id' => $ayaan->customer_id, 'name' => 'Later', 'birth_month' => 12, 'birth_day' => 1]);
    parentWithChild(11, 20, 'NoConsent', consent: false);

    expect(remind('2026-11-13 03:30:00'))->toBe(['queued' => 0, 'skipped' => 0]); // 09:30 Dhaka: too early
    expect(remind('2026-11-13 04:00:00'))->toBe(['queued' => 1, 'skipped' => 0]) // 20 Nov is 7 days away; 22 Nov is 9
        ->and(remind('2026-11-13 07:00:00'))->toBe(['queued' => 0, 'skipped' => 0]);

    $send = AutomationSend::query()->sole();
    expect($send->subject_id)->toBe($ayaan->id)->and($send->period)->toBe('2026')->and($send->coupon_id)->toBeNull()
        ->and($sms->sent[0]['to'])->toBe(Customer::query()->find($ayaan->customer_id)?->phone)
        ->and($sms->sent[0]['body'])->toBe("Toy Mart BD: Ayaan's birthday is on 20 Nov 2026! Gifts: toymartbd.com");

    expect(remind('2026-11-15 04:00:00'))->toBe(['queued' => 1, 'skipped' => 0]) // Mehrin, 7 days ahead
        ->and(AutomationSend::query()->where('subject_id', $mehrin->id)->exists())->toBeTrue()
        ->and(AutomationSend::query()->where('subject_id', $later->id)->exists())->toBeFalse();

    // A missed day still reminds (inside the window), once: Later's birthday is 1 Dec.
    expect(remind('2026-11-28 05:00:00'))->toBe(['queued' => 1, 'skipped' => 0])
        ->and(remind('2026-11-30 05:00:00'))->toBe(['queued' => 0, 'skipped' => 0]);

    // Next year, again — once.
    expect(remind('2027-11-14 04:00:00'))->toBe(['queued' => 1, 'skipped' => 0])
        ->and(remind('2027-11-14 09:00:00'))->toBe(['queued' => 0, 'skipped' => 0]);
});

it('follows the lead-days setting, including 0 = on the day', function (): void {
    reminderSms();
    $child = parentWithChild(3, 12);
    app(App\Domain\Notification\Actions\UpdateAutomationSettings::class)->handle(3, '21:00', '09:00', null, 0);

    expect(remind('2027-03-11 05:00:00'))->toBe(['queued' => 0, 'skipped' => 0])
        ->and(remind('2027-03-12 05:00:00'))->toBe(['queued' => 1, 'skipped' => 0])
        ->and(AutomationSend::query()->sole()->subject_id)->toBe($child->id);
});

it('celebrates 29 February on 28 February in a non-leap year, and on the 29th in a leap year', function (): void {
    reminderSms();
    $leap = parentWithChild(2, 29, 'Leap');

    expect(remind('2027-02-20 05:00:00'))->toBe(['queued' => 0, 'skipped' => 0]) // 8 days before 28 Feb
        ->and(remind('2027-02-21 05:00:00'))->toBe(['queued' => 1, 'skipped' => 0]);
    $body = NotificationLog::query()->sole()->payload;
    expect($body['birthday_date'] ?? null)->toBe('28 Feb 2027');

    expect(remind('2028-02-22 05:00:00'))->toBe(['queued' => 1, 'skipped' => 0])
        ->and(NotificationLog::query()->orderByDesc('id')->first()?->payload['birthday_date'])->toBe('29 Feb 2028')
        ->and(AutomationSend::query()->where('subject_id', $leap->id)->pluck('period')->all())->toBe(['2027', '2028']);
});

it('crosses the new year: a 3 January birthday is reminded in late December, keyed to the new year', function (): void {
    reminderSms();
    $child = parentWithChild(1, 3);

    expect(remind('2026-12-27 05:00:00'))->toBe(['queued' => 1, 'skipped' => 0])
        ->and(AutomationSend::query()->sole()->period)->toBe('2027')
        ->and(remind('2027-01-02 05:00:00'))->toBe(['queued' => 0, 'skipped' => 0])
        ->and($child->id)->toBeInt();
});

it('stays silent inside the quiet hours and catches up after', function (): void {
    reminderSms();
    parentWithChild(11, 20);
    app(App\Domain\Notification\Actions\UpdateAutomationSettings::class)->handle(3, '09:00', '12:00', null);

    expect(remind('2026-11-13 04:30:00'))->toBe(['queued' => 0, 'skipped' => 0]) // 10:30 Dhaka, quiet
        ->and(remind('2026-11-13 06:00:00'))->toBe(['queued' => 1, 'skipped' => 0]);
});

it('mints no coupon while the offer is off, and sends without a code', function (): void {
    $sms = reminderSms();
    parentWithChild(11, 20, null);

    // Saved placeholders but switched off: still nothing.
    offerOn(['enabled' => false]);
    expect(remind('2026-11-13 04:00:00'))->toBe(['queued' => 1, 'skipped' => 0])
        ->and(Coupon::query()->count())->toBe(0)
        ->and(AutomationSend::query()->sole()->coupon_id)->toBeNull()
        ->and($sms->sent[0]['body'])->toBe("Toy Mart BD: your child's birthday is on 20 Nov 2026! Gifts: toymartbd.com");
});

it('does not mint from the placeholder values that were never saved', function (): void {
    reminderSms();
    parentWithChild(11, 20);
    App\Models\Setting::put(AutomationSettings::OFFER_ENABLED, true, App\Models\Enums\SettingGroup::Notification);

    expect(app(AutomationSettings::class)->birthdayOffer()['is_placeholder'])->toBeTrue()
        ->and(remind('2026-11-13 04:00:00'))->toBe(['queued' => 1, 'skipped' => 0])
        ->and(Coupon::query()->count())->toBe(0);
});

it('mints one personal single-use coupon per send with the offer on; a replay mints none', function (): void {
    $sms = reminderSms();
    $child = parentWithChild(11, 20, 'Ayaan');
    $other = parentWithChild(11, 19, 'Zara');
    offerOn();

    expect(remind('2026-11-13 04:00:00'))->toBe(['queued' => 2, 'skipped' => 0])
        ->and(remind('2026-11-13 05:00:00'))->toBe(['queued' => 0, 'skipped' => 0])
        ->and(remind('2026-11-14 05:00:00'))->toBe(['queued' => 0, 'skipped' => 0]);

    expect(Coupon::query()->count())->toBe(2);
    $send = AutomationSend::query()->where('subject_id', $child->id)->sole();
    $coupon = Coupon::query()->findOrFail($send->coupon_id);
    expect($coupon->customer_id)->toBe($child->customer_id)
        ->and($coupon->code)->toStartWith('BDAY-')
        ->and($coupon->type)->toBe(CouponType::Percent)
        ->and($coupon->value->toDecimal())->toBe('10.00')
        ->and($coupon->max_discount?->toDecimal())->toBe('300.00')
        ->and($coupon->usage_limit_total)->toBe(1)
        ->and($coupon->usage_limit_per_customer)->toBe(1)
        ->and($coupon->batch)->toBe('birthday-reminder')
        // Valid through the 14th Dhaka day after the send (13 Nov → 27 Nov inclusive).
        ->and($coupon->ends_at?->toIso8601String())->toBe('2026-11-27T18:00:00+00:00');

    $body = collect($sms->sent)->firstWhere('to', Customer::query()->find($child->customer_id)?->phone)['body'] ?? '';
    expect($body)->toBe("Toy Mart BD: Ayaan's birthday is on 20 Nov 2026! Gift code {$coupon->code}: 10% off, valid till 27 Nov 2026. Gifts: toymartbd.com")
        ->and($other->id)->toBeInt();

    // A second scheduler racing the first fails on UNIQUE and mints nothing either.
    AutomationSend::query()->where('subject_id', $child->id)->delete();
    $before = Coupon::query()->count();
    AutomationSend::query()->create(['automation' => 'birthday', 'subject_id' => $child->id, 'period' => '2026', 'customer_id' => $child->customer_id, 'status' => 'queued']);
    expect(remind('2026-11-13 06:00:00'))->toBe(['queued' => 0, 'skipped' => 0])
        ->and(Coupon::query()->count())->toBe($before);
});

it('writes a fixed offer as BDT in English and Bangla', function (): void {
    $sms = reminderSms();
    parentWithChild(11, 20, 'আয়ান', ['preferred_language' => 'bn']);
    offerOn(['type' => 'fixed', 'value' => '200.00', 'max_discount' => '999.00', 'valid_days' => 7]);

    remind('2026-11-13 04:00:00');
    $coupon = Coupon::query()->sole();
    expect($coupon->type)->toBe(CouponType::Fixed)->and($coupon->max_discount)->toBeNull()
        ->and($sms->sent[0]['body'])->toBe("টয় মার্ট বিডি: 20 নভেম্বর 2026 আয়ান-এর জন্মদিন! উপহার কোড {$coupon->code}: BDT 200 ছাড়, 20 নভেম্বর 2026 পর্যন্ত। toymartbd.com");
});

it('lets only that customer redeem the personal coupon', function (): void {
    reminderSms();
    $child = parentWithChild(11, 20, 'Ayaan', ['phone' => '+8801711111111']);
    offerOn();
    remind('2026-11-13 04:00:00');
    $coupon = Coupon::query()->sole();
    $evaluator = app(CouponEvaluator::class);
    $now = Carbon::parse('2026-11-14 04:00:00');

    expect($evaluator->eligibility($coupon, $now, PhoneNumber::parse('01711111111'), null))->toBeNull()
        ->and($evaluator->eligibility($coupon, $now, null, $child->customer_id))->toBeNull()
        ->and($evaluator->eligibility($coupon, $now, null, null))->toBeNull() // not known yet: checkout decides
        ->and($evaluator->eligibility($coupon, $now, PhoneNumber::parse('01811111111'), null))->toBe(CouponRejection::NotForCustomer)
        ->and($evaluator->eligibility($coupon, $now, null, $child->customer_id + 999))->toBe(CouponRejection::NotForCustomer)
        ->and($evaluator->eligibility($coupon, Carbon::parse('2026-11-28 00:00:00'), PhoneNumber::parse('01711111111'), null))->toBe(CouponRejection::Expired)
        ->and(CouponRejection::NotForCustomer->message())->not->toBe('');
});

it('respects consent: withdrawal stops the next send, a marketing opt-out wins, blocked customers are skipped', function (): void {
    reminderSms();
    $withdrawn = parentWithChild(11, 20);
    app(SetMessageConsent::class)->handle($withdrawn->customer_id, MessageConsentPurpose::Birthday, false, 'storefront', null);
    $optedOut = parentWithChild(11, 20);
    app(SetNotificationPreference::class)->handle($optedOut->customer_id, NotificationChannel::Sms, NotificationCategory::Marketing, false, 'admin');
    $blocked = parentWithChild(11, 20, 'B', ['status' => CustomerStatus::Blocked]);
    offerOn();

    expect(remind('2026-11-13 04:00:00'))->toBe(['queued' => 0, 'skipped' => 2])
        ->and(AutomationSend::query()->where('subject_id', $withdrawn->id)->exists())->toBeFalse()
        ->and(AutomationSend::query()->where('subject_id', $optedOut->id)->sole()->skip_reason)->toBe(AutomationSkip::OptedOut)
        ->and(AutomationSend::query()->where('subject_id', $blocked->id)->sole()->skip_reason)->toBe(AutomationSkip::Blocked)
        ->and(Coupon::query()->count())->toBe(0) // no coupon for a skipped send
        ->and(NotificationLog::query()->count())->toBe(0);
});

it('forgets a removed child in the logged messages and never sends a queued one', function (): void {
    $child = parentWithChild(11, 20, 'Ayaan');
    config(['notifications.sms.driver' => 'null', 'notifications.whatsapp.enabled' => false, 'queue.default' => 'null']);
    remind('2026-11-13 04:00:00');
    $log = NotificationLog::query()->sole();
    expect($log->status)->toBe(NotificationStatus::Queued)->and($log->payload['child_possessive'] ?? null)->toBe("Ayaan's");

    app(RemoveCustomerChild::class)->handle($child);

    $log->refresh();
    expect(CustomerChild::query()->count())->toBe(0)
        ->and($log->payload['child_possessive'] ?? null)->toBe("your child's")
        ->and(json_encode($log->payload))->not->toContain('Ayaan')
        ->and($log->status)->toBe(NotificationStatus::Failed)
        ->and($log->error)->toBe('child_removed')
        ->and(AutomationSend::query()->count())->toBe(1); // the once-a-year proof stays (ids only)
});

it('never logs a child\'s name', function (): void {
    reminderSms();
    Log::spy();
    $child = parentWithChild(11, 20, 'Ayaan');
    offerOn();
    remind('2026-11-13 04:00:00');
    app(RemoveCustomerChild::class)->handle($child);

    Log::shouldNotHaveReceived('info', [Mockery::on(fn ($m) => str_contains((string) $m, 'Ayaan')), Mockery::any()]);
    foreach (['debug', 'info', 'notice', 'warning', 'error'] as $level) {
        Log::shouldNotHaveReceived($level, [Mockery::on(fn ($m) => str_contains((string) $m, 'Ayaan'))]);
    }
});

it('sends WhatsApp first when on with a non-empty offer line, falling back to SMS if refused', function (): void {
    $sms = reminderSms();
    config(['notifications.whatsapp.enabled' => true]);
    $refusing = new class implements App\Domain\Notification\Contracts\WhatsAppGateway
    {
        /** @var list<array{template: string, parameters: list<string>}> */
        public array $sent = [];

        public function sendTemplate(string $e164Phone, string $providerTemplate, string $language, array $parameters, string $template): App\Domain\Notification\DTOs\WhatsAppResult
        {
            $this->sent[] = ['template' => $providerTemplate, 'parameters' => $parameters];

            return App\Domain\Notification\DTOs\WhatsAppResult::rejected('template not approved');
        }
    };
    app()->instance(App\Domain\Notification\Contracts\WhatsAppGateway::class, $refusing);
    parentWithChild(11, 20, 'আয়ান', ['name' => 'নুসরাত জাহান', 'preferred_language' => 'bn']);

    remind('2026-11-13 04:00:00');

    $logs = NotificationLog::query()->where('template', 'customer.birthday')->orderBy('id')->get();
    expect($refusing->sent)->toBe([['template' => 'child_birthday_reminder', 'parameters' => ['নুসরাত', 'আয়ান-এর', '20 নভেম্বর 2026', 'সব বয়সের জন্য উপহার আছে আমাদের কাছে।']]])
        ->and($logs->pluck('channel')->all())->toBe([NotificationChannel::WhatsApp, NotificationChannel::Sms])
        ->and($logs[1]->status)->toBe(NotificationStatus::Sent)
        ->and($sms->sent[0]['body'])->toStartWith('টয় মার্ট বিডি: 20 নভেম্বর 2026 আয়ান-এর জন্মদিন!');
});

it('measures the new copy: English SMS one segment with an offer, Bangla two', function (): void {
    $sms = NotificationTemplate::for('customer.birthday', NotificationChannel::Sms);
    expect($sms?->segments_en)->toBe(1)->and($sms?->segments_bn)->toBe(2)
        ->and(NotificationTemplate::for('customer.birthday', NotificationChannel::WhatsApp)?->provider_template)->toBe('child_birthday_reminder');
});

it('moves an untouched batch-1 template to the new copy, keeping the old one as a version, but never an edited one', function (): void {
    $row = NotificationTemplate::for('customer.birthday', NotificationChannel::Sms);
    $row?->forceFill(['body_en' => 'Happy birthday, {name}!', 'body_bn' => 'শুভ জন্মদিন, {name}!'])->save();
    $replace = app(ReplaceUnchangedDefaultTemplate::class);

    expect($replace->handle('customer.birthday', NotificationChannel::Sms, 'Something else', 'অন্য কিছু'))->toBeFalse()
        ->and($row?->refresh()->body_en)->toBe('Happy birthday, {name}!')
        ->and($replace->handle('customer.birthday', NotificationChannel::Sms, 'Happy birthday, {name}!', 'শুভ জন্মদিন, {name}!'))->toBeTrue()
        ->and($row?->refresh()->body_en)->toStartWith('Toy Mart BD: {child_possessive}')
        ->and(NotificationTemplateVersion::query()->where('notification_template_id', $row?->id)->orderBy('version')->pluck('body_en')->all())
        ->toBe(['Happy birthday, {name}!', $row?->body_en]);
});

it('reads and saves lead days and the offer on Settings → Automations', function (): void {
    Tests\Feature\Orders\Support::actingAs(Role::Manager);
    $base = ['review_delay_days' => 3, 'quiet_from' => '21:00', 'quiet_to' => '09:00'];
    $this->getJson('/api/v1/admin/notifications/automations')->assertOk()
        ->assertJsonPath('birthday_lead_days', 7)
        ->assertJsonPath('birthday_offer', ['enabled' => false, 'type' => 'percent', 'value' => '10.00', 'max_discount' => '300.00', 'min_order_amount' => '0.00', 'valid_days' => 14, 'is_placeholder' => true]);

    $offer = ['enabled' => true, 'type' => 'percent', 'value' => '15', 'max_discount' => null, 'min_order_amount' => '500', 'valid_days' => 10];
    foreach ([
        ['birthday_lead_days' => 31],
        ['birthday_lead_days' => -1],
        ['birthday_offer' => [...$offer, 'value' => '101']],
        ['birthday_offer' => [...$offer, 'value' => '0']],
        ['birthday_offer' => [...$offer, 'type' => 'free_shipping']],
        ['birthday_offer' => [...$offer, 'valid_days' => 0]],
        ['birthday_offer' => [...$offer, 'valid_days' => 91]],
        ['birthday_offer' => [...$offer, 'value' => '1.234']],
    ] as $bad) {
        $this->putJson('/api/v1/admin/notifications/automations', [...$base, ...$bad])->assertUnprocessable();
    }

    $this->putJson('/api/v1/admin/notifications/automations', [...$base, 'birthday_lead_days' => 5, 'birthday_offer' => $offer])->assertOk()
        ->assertJsonPath('birthday_lead_days', 5)
        ->assertJsonPath('birthday_offer', ['enabled' => true, 'type' => 'percent', 'value' => '15.00', 'max_discount' => null, 'min_order_amount' => '500.00', 'valid_days' => 10, 'is_placeholder' => false]);

    // Leaving the birthday fields out leaves them as they are.
    $this->putJson('/api/v1/admin/notifications/automations', $base)->assertOk()
        ->assertJsonPath('birthday_lead_days', 5)->assertJsonPath('birthday_offer.enabled', true);
});
