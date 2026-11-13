<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Notification\Actions\SendBirthdayMessages;
use App\Domain\Notification\Actions\SendReviewRequests;
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
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Models\Enums\Permission;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\RoleMatrix;

/*
 * P8 notification automation: review requests (N days after delivery, once per order,
 * marketing consent, quiet hours, link to the review form) and birthday messages (consent
 * required, 10:00 Dhaka, once a year). A second run sends nothing. WhatsApp templates are in
 * the library as "not submitted"; WhatsApp stays off.
 */

final class AutomationRecordingSms implements SmsGateway
{
    /** @var list<array{to: string, body: string, template: string}> */
    public array $sent = [];

    public function send(string $e164Phone, string $body, string $template): SmsResult
    {
        $this->sent[] = ['to' => $e164Phone, 'body' => $body, 'template' => $template];

        return SmsResult::accepted('auto-1', 1, Money::of('0.26'));
    }
}

function automationSms(): AutomationRecordingSms
{
    $fake = new AutomationRecordingSms;
    app()->bind(App\Domain\Notification\Drivers\NullSmsGateway::class, fn () => $fake);
    config(['notifications.sms.driver' => 'null', 'notifications.whatsapp.enabled' => false]);

    return $fake;
}

function optInMarketing(int $customerId, bool $enabled = true): void
{
    app(SetNotificationPreference::class)->handle($customerId, NotificationChannel::Sms, NotificationCategory::Marketing, $enabled, 'admin');
}

function deliveredOrder(Carbon $deliveredAt, array $customer = []): Order
{
    $c = Customer::factory()->create(['name' => 'নুসরাত জাহান', 'preferred_language' => 'bn', ...$customer]);
    $o = Order::factory()->status(OrderStatus::Delivered)->create(['customer_id' => $c->id, 'delivered_at' => $deliveredAt, 'shipping_phone' => $c->phone]);
    OrderItem::factory()->create(['order_id' => $o->id]);

    return $o->refresh();
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => 'https://toymartbd.com', 'services.storefront.revalidate_secret' => '']);
});

it('ships the review and birthday templates in the library, WhatsApp not submitted', function (): void {
    foreach (['review.request', 'customer.birthday'] as $key) {
        $wa = NotificationTemplate::query()->where('key', $key)->where('channel', 'whatsapp')->sole();
        $sms = NotificationTemplate::query()->where('key', $key)->where('channel', 'sms')->sole();
        expect($wa->whatsapp_status)->toBeNull() // null reads as draft = not submitted
            ->and($wa->provider_template)->not->toBeNull()
            ->and($sms->category)->toBe(NotificationCategory::Marketing)
            ->and($sms->segments_bn)->toBeGreaterThan(0);
    }
    expect(NotificationTemplate::query()->where('key', 'otp.login')->where('channel', 'whatsapp')->exists())->toBeFalse();
});

it('asks an opted-in customer for a review once, N days after delivery, with the review link', function (): void {
    $sms = automationSms();
    $now = Carbon::parse('2026-11-13 06:00:00'); // 12:00 Dhaka
    $order = deliveredOrder($now->copy()->subDays(3)->subHour());
    $slug = Product::query()->whereKey($order->items->first()->product_id)->value('slug');
    optInMarketing($order->customer_id);

    expect(app(SendReviewRequests::class)->handle($now))->toBe(['queued' => 1, 'skipped' => 0])
        ->and(app(SendReviewRequests::class)->handle($now))->toBe(['queued' => 0, 'skipped' => 0])
        ->and(app(SendReviewRequests::class)->handle($now->copy()->addDay()))->toBe(['queued' => 0, 'skipped' => 0]);

    $log = NotificationLog::query()->where('template', 'review.request')->sole();
    expect($log->status)->toBe(NotificationStatus::Sent)
        ->and($log->channel)->toBe(NotificationChannel::Sms)
        ->and($sms->sent)->toHaveCount(1)
        ->and($sms->sent[0]['body'])->toContain($order->number)->toContain("https://toymartbd.com/bn/products/{$slug}#reviews")
        ->and(AutomationSend::query()->sole()->notification_log_id)->toBe($log->id);
});

it('waits for the delay, skips old orders and stays quiet at night', function (): void {
    automationSms();
    $now = Carbon::parse('2026-11-13 06:00:00');
    $recent = deliveredOrder($now->copy()->subDays(2));
    $ancient = deliveredOrder($now->copy()->subDays(30));
    optInMarketing($recent->customer_id);
    optInMarketing($ancient->customer_id);

    expect(app(SendReviewRequests::class)->handle($now))->toBe(['queued' => 0, 'skipped' => 0]);
    // 23:00 Dhaka is inside the default 21:00–09:00 quiet hours.
    expect(app(SendReviewRequests::class)->handle(Carbon::parse('2026-11-14 17:00:00')))->toBe(['queued' => 0, 'skipped' => 0])
        ->and(app(SendReviewRequests::class)->handle(Carbon::parse('2026-11-15 06:00:00')))->toBe(['queued' => 1, 'skipped' => 0])
        ->and(AutomationSend::query()->where('subject_id', $ancient->id)->exists())->toBeFalse();
});

it('respects consent: no opt-in is skipped without a message, an opt-out wins', function (): void {
    $sms = automationSms();
    $now = Carbon::parse('2026-11-13 06:00:00');
    $silent = deliveredOrder($now->copy()->subDays(4));
    $out = deliveredOrder($now->copy()->subDays(4));
    optInMarketing($out->customer_id, false);

    expect(app(SendReviewRequests::class)->handle($now))->toBe(['queued' => 0, 'skipped' => 2])
        ->and(AutomationSend::query()->where('subject_id', $silent->id)->value('skip_reason'))->toBe(AutomationSkip::NoConsent)
        ->and(AutomationSend::query()->where('subject_id', $out->id)->value('skip_reason'))->toBe(AutomationSkip::OptedOut)
        ->and(NotificationLog::query()->count())->toBe(0)
        ->and($sms->sent)->toBe([]);
});

it('does nothing when the review template is switched off', function (): void {
    automationSms();
    $now = Carbon::parse('2026-11-13 06:00:00');
    $order = deliveredOrder($now->copy()->subDays(4));
    optInMarketing($order->customer_id);
    NotificationTemplate::query()->where('key', 'review.request')->update(['is_active' => false]);

    expect(app(SendReviewRequests::class)->handle($now))->toBe(['queued' => 0, 'skipped' => 1])
        ->and(AutomationSend::query()->sole()->skip_reason)->toBe(AutomationSkip::TemplateInactive);
});

it('sends a birthday message at 10:00 Dhaka to a consented customer, once a year', function (): void {
    $sms = automationSms();
    $customer = Customer::factory()->create(['name' => 'Rahim Uddin', 'preferred_language' => 'en', 'date_of_birth' => '1990-11-13']);
    $other = Customer::factory()->create(['date_of_birth' => '1991-11-13']); // no consent
    app(SetMessageConsent::class)->handle($customer->id, MessageConsentPurpose::Birthday, true, 'admin', null);

    // 09:30 Dhaka: too early.
    expect(app(SendBirthdayMessages::class)->handle(Carbon::parse('2026-11-13 03:30:00')))->toBe(['queued' => 0, 'skipped' => 0]);
    $at = Carbon::parse('2026-11-13 04:00:00'); // 10:00 Dhaka
    expect(app(SendBirthdayMessages::class)->handle($at))->toBe(['queued' => 1, 'skipped' => 0])
        ->and(app(SendBirthdayMessages::class)->handle($at->copy()->addHours(3)))->toBe(['queued' => 0, 'skipped' => 0]);

    $log = NotificationLog::query()->where('template', 'customer.birthday')->sole();
    expect($log->status)->toBe(NotificationStatus::Sent)
        ->and($log->notifiable_type)->toBe(NotificationLog::NOTIFIABLE_CUSTOMER)
        ->and($log->recipient)->not->toContain(substr($customer->phone, -8))
        ->and($sms->sent)->toHaveCount(1)
        ->and($sms->sent[0]['to'])->toBe($customer->phone)
        ->and($sms->sent[0]['body'])->toStartWith('Happy birthday, Rahim!')
        ->and(AutomationSend::query()->where('subject_id', $other->id)->exists())->toBeFalse();

    // Next year: again, once.
    expect(app(SendBirthdayMessages::class)->handle(Carbon::parse('2027-11-13 05:00:00')))->toBe(['queued' => 1, 'skipped' => 0]);
});

it('skips a consented customer who opted out of marketing SMS, and honours a withdrawn consent', function (): void {
    automationSms();
    $out = Customer::factory()->create(['date_of_birth' => '1990-11-13']);
    $withdrawn = Customer::factory()->create(['date_of_birth' => '1990-11-13']);
    app(SetMessageConsent::class)->handle($out->id, MessageConsentPurpose::Birthday, true, 'admin', null);
    optInMarketing($out->id, false);
    app(SetMessageConsent::class)->handle($withdrawn->id, MessageConsentPurpose::Birthday, true, 'storefront', null);
    app(SetMessageConsent::class)->handle($withdrawn->id, MessageConsentPurpose::Birthday, false, 'storefront', null);

    expect(app(SendBirthdayMessages::class)->handle(Carbon::parse('2026-11-13 04:30:00')))->toBe(['queued' => 0, 'skipped' => 1])
        ->and(AutomationSend::query()->sole()->skip_reason)->toBe(AutomationSkip::OptedOut)
        ->and(NotificationLog::query()->count())->toBe(0);
});

it('celebrates a 29 February birthday on 28 February in a non-leap year', function (): void {
    automationSms();
    $leap = Customer::factory()->create(['date_of_birth' => '2000-02-29']);
    app(SetMessageConsent::class)->handle($leap->id, MessageConsentPurpose::Birthday, true, 'admin', null);

    expect(app(SendBirthdayMessages::class)->handle(Carbon::parse('2027-02-28 05:00:00')))->toBe(['queued' => 1, 'skipped' => 0]);
});

it('reads and saves the automation settings and the birthday consent', function (): void {
    Tests\Feature\Orders\Support::actingAs(App\Models\Enums\Role::Manager);
    $this->getJson('/api/v1/admin/notifications/automations')->assertOk()
        ->assertJsonPath('review_delay_days', 3)->assertJsonPath('quiet_from', '21:00')->assertJsonPath('birthday_send_at', '10:00')
        ->assertJsonPath('stats.review_request.queued', 0);
    $this->putJson('/api/v1/admin/notifications/automations', ['review_delay_days' => 0, 'quiet_from' => '21:00', 'quiet_to' => '09:00'])->assertUnprocessable();
    $this->putJson('/api/v1/admin/notifications/automations', ['review_delay_days' => 5, 'quiet_from' => '22:00', 'quiet_to' => '08:30'])
        ->assertOk()->assertJsonPath('review_delay_days', 5)->assertJsonPath('quiet_to', '08:30');

    $customer = Customer::factory()->create();
    $this->getJson("/api/v1/admin/notifications/customers/{$customer->id}/consents")->assertOk()->assertJsonPath('birthday.consented', false);
    $this->putJson("/api/v1/admin/notifications/customers/{$customer->id}/consents", ['birthday' => true, 'customer_confirmed' => true])
        ->assertOk()->assertJsonPath('birthday.consented', true)->assertJsonPath('birthday.source', 'admin');
});

describe('role matrix', function (): void {
    it('guards the automation settings (settings.view / settings.update)', function (): void {
        RoleMatrix::assert($this, Permission::SettingsView, fn () => $this->getJson('/api/v1/admin/notifications/automations'));
        RoleMatrix::assert($this, Permission::SettingsUpdate, fn () => $this->putJson('/api/v1/admin/notifications/automations', ['review_delay_days' => 3, 'quiet_from' => '21:00', 'quiet_to' => '09:00']));
    });

    it('guards the birthday consent (customers.view / customers.update)', function (): void {
        $customer = Customer::factory()->create();
        RoleMatrix::assert($this, Permission::CustomersView, fn () => $this->getJson("/api/v1/admin/notifications/customers/{$customer->id}/consents"));
        RoleMatrix::assert($this, Permission::CustomersUpdate, fn () => $this->putJson("/api/v1/admin/notifications/customers/{$customer->id}/consents", ['birthday' => true, 'customer_confirmed' => true]));
    });
});

it('sends a birthday message over WhatsApp first when WhatsApp is on, falling back to SMS if refused', function (): void {
    $sms = automationSms();
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
    $customer = Customer::factory()->create(['name' => 'নুসরাত জাহান', 'date_of_birth' => '1990-11-13']);
    app(SetMessageConsent::class)->handle($customer->id, MessageConsentPurpose::Birthday, true, 'admin', null);

    app(SendBirthdayMessages::class)->handle(Carbon::parse('2026-11-13 04:00:00'));

    $logs = NotificationLog::query()->where('template', 'customer.birthday')->orderBy('id')->get();
    expect($refusing->sent)->toBe([['template' => 'customer_birthday', 'parameters' => ['নুসরাত']]])
        ->and($logs->pluck('channel')->all())->toBe([NotificationChannel::WhatsApp, NotificationChannel::Sms])
        ->and($logs[1]->status)->toBe(NotificationStatus::Sent)
        ->and($sms->sent[0]['body'])->toStartWith('শুভ জন্মদিন, নুসরাত!');
});
