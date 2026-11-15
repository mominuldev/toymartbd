<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Notification\Contracts\SmsGateway;
use App\Domain\Notification\Contracts\WhatsAppGateway;
use App\Domain\Notification\DTOs\SmsResult;
use App\Domain\Notification\DTOs\WhatsAppResult;
use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Notification\Models\NotificationPreference;
use App\Domain\Notification\Services\NotificationPreferences;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Support\Money;
use Tests\Feature\Storefront\Account\AccountTestKit;

/*
 * The profile page's "Order updates on WhatsApp" and "SMS updates" switches: the signed-in customer's
 * own order-update channels. Customer token only; no id in the path, so another customer's switches
 * are out of reach; security (sign-in codes) can never be turned off; QueueOrderNotification honours
 * the choice (WhatsApp off → SMS).
 */

const PREFS_URL = '/api/v1/storefront/account/notification-preferences';

beforeEach(function (): void {
    $this->me = Customer::factory()->create(['name' => 'সাদিয়া ইসলাম', 'phone' => '+8801712345670', 'preferred_language' => 'bn']);
    $this->them = Customer::factory()->create(['phone' => '+8801812345670']);
    $this->meToken = AccountTestKit::tokenFor($this->me);
    $this->themToken = AccountTestKit::tokenFor($this->them);
});

function prefsAs(string $token): Illuminate\Foundation\Testing\TestCase
{
    app('auth')->forgetGuards(); // two customers in one test: never reuse the resolved guard

    return test()->withToken($token);
}

/** @return list<array{channel: string, category: string, enabled: bool}> */
function prefsBody(bool $whatsapp, bool $sms): array
{
    return ['preferences' => [
        ['channel' => 'whatsapp', 'category' => 'order_updates', 'enabled' => $whatsapp],
        ['channel' => 'sms', 'category' => 'order_updates', 'enabled' => $sms],
    ]];
}

it('refuses a guest with 401 and records nothing', function (): void {
    $this->getJson(PREFS_URL)->assertUnauthorized();
    $this->putJson(PREFS_URL, prefsBody(false, true))->assertUnauthorized();
    expect(NotificationPreference::query()->count())->toBe(0);
});

it('refuses a storefront (non-customer) token', function (): void {
    $staff = App\Models\User::factory()->create(['is_active' => false])->createToken('storefront', ['storefront:read'])->plainTextToken;
    prefsAs($staff)->getJson(PREFS_URL)->assertUnauthorized();
});

it('starts with order updates on for both channels, nothing stored', function (): void {
    config(['notifications.whatsapp.enabled' => true]);

    prefsAs($this->meToken)->getJson(PREFS_URL)->assertOk()->assertExactJson([
        'preferences' => [
            ['channel' => 'sms', 'category' => 'order_updates', 'enabled' => true, 'is_default' => true],
            ['channel' => 'whatsapp', 'category' => 'order_updates', 'enabled' => true, 'is_default' => true],
        ],
        'whatsapp_available' => true,
    ]);
});

it('says when the shop sends no WhatsApp at all', function (): void {
    config(['notifications.whatsapp.enabled' => false]);
    prefsAs($this->meToken)->getJson(PREFS_URL)->assertOk()->assertJsonPath('whatsapp_available', false);
});

it('turns WhatsApp order updates off from the storefront, recorded with source storefront', function (): void {
    prefsAs($this->meToken)->putJson(PREFS_URL, prefsBody(false, true))->assertOk()
        ->assertJsonPath('preferences.0', ['channel' => 'sms', 'category' => 'order_updates', 'enabled' => true, 'is_default' => false])
        ->assertJsonPath('preferences.1', ['channel' => 'whatsapp', 'category' => 'order_updates', 'enabled' => false, 'is_default' => false]);

    $row = NotificationPreference::query()->where('channel', 'whatsapp')->sole();
    expect($row->customer_id)->toBe($this->me->id)
        ->and($row->enabled)->toBeFalse()
        ->and($row->source)->toBe('storefront')
        ->and($row->updated_by)->toBeNull()
        ->and(app(NotificationPreferences::class)->allows($this->me->id, NotificationChannel::WhatsApp, NotificationCategory::OrderUpdates))->toBeFalse();

    // A single row is fine; the other channel keeps its setting.
    prefsAs($this->meToken)->putJson(PREFS_URL, ['preferences' => [['channel' => 'whatsapp', 'category' => 'order_updates', 'enabled' => true]]])->assertOk()
        ->assertJsonPath('preferences.1.enabled', true)->assertJsonPath('preferences.0.enabled', true);
});

it('never lets one customer read or change another customer\'s switches', function (): void {
    prefsAs($this->themToken)->putJson(PREFS_URL, prefsBody(false, false))->assertOk();

    prefsAs($this->meToken)->getJson(PREFS_URL.'?customer_id='.$this->them->id)->assertOk()
        ->assertJsonPath('preferences.0.enabled', true)->assertJsonPath('preferences.1.enabled', true);
    prefsAs($this->meToken)->putJson(PREFS_URL, [...prefsBody(true, true), 'customer_id' => $this->them->id])->assertOk();

    $prefs = app(NotificationPreferences::class);
    expect($prefs->allows($this->them->id, NotificationChannel::WhatsApp, NotificationCategory::OrderUpdates))->toBeFalse()
        ->and($prefs->allows($this->them->id, NotificationChannel::Sms, NotificationCategory::OrderUpdates))->toBeFalse()
        ->and(NotificationPreference::query()->where('customer_id', $this->them->id)->where('enabled', true)->exists())->toBeFalse();
});

it('refuses to turn off security messages (sign-in codes) with a 422', function (): void {
    prefsAs($this->meToken)->putJson(PREFS_URL, ['preferences' => [['channel' => 'sms', 'category' => 'security', 'enabled' => false]]])
        ->assertUnprocessable()
        ->assertJsonPath('type', fn (string $t): bool => str_ends_with($t, 'validation-error'))
        ->assertJsonPath('errors.0.field', 'preferences.0.category')
        ->assertJsonPath('errors.0.message', __('account.notifications.security_locked'));
    expect(NotificationPreference::query()->count())->toBe(0);
});

it('accepts only the allowlisted channels and category', function (array $body): void {
    prefsAs($this->meToken)->putJson(PREFS_URL, $body)->assertUnprocessable();
    expect(NotificationPreference::query()->count())->toBe(0);
})->with([
    'empty' => [[]],
    'no rows' => [['preferences' => []]],
    'email channel' => [['preferences' => [['channel' => 'email', 'category' => 'order_updates', 'enabled' => false]]]],
    'marketing' => [['preferences' => [['channel' => 'sms', 'category' => 'marketing', 'enabled' => true]]]],
    'unknown channel' => [['preferences' => [['channel' => 'telegram', 'category' => 'order_updates', 'enabled' => true]]]],
    'not a boolean' => [['preferences' => [['channel' => 'sms', 'category' => 'order_updates', 'enabled' => 'maybe']]]],
    'a channel twice' => [['preferences' => [['channel' => 'sms', 'category' => 'order_updates', 'enabled' => true], ['channel' => 'sms', 'category' => 'order_updates', 'enabled' => false]]]],
]);

it('sends the next order update by SMS once the customer turned WhatsApp off on the storefront', function (): void {
    config(['notifications.whatsapp.enabled' => true]);
    $wa = new class implements WhatsAppGateway
    {
        public int $sent = 0;

        public function sendTemplate(string $e164Phone, string $providerTemplate, string $language, array $parameters, string $template): WhatsAppResult
        {
            $this->sent++;

            return WhatsAppResult::accepted('wamid.1', Money::of('0.45'));
        }
    };
    $sms = new class implements SmsGateway
    {
        /** @var list<string> */
        public array $sent = [];

        public function send(string $e164Phone, string $body, string $template): SmsResult
        {
            $this->sent[] = $e164Phone;

            return SmsResult::accepted('sms-1', 2, Money::of('1.28'));
        }
    };
    app()->instance(WhatsAppGateway::class, $wa);
    app()->bind(App\Domain\Notification\Drivers\NullSmsGateway::class, fn () => $sms);
    config(['notifications.sms.driver' => 'null']);

    prefsAs($this->meToken)->putJson(PREFS_URL, prefsBody(false, true))->assertOk();

    $order = Order::factory()->create(['customer_id' => $this->me->id, 'shipping_phone' => '+8801712345670', 'grand_total' => '2450.00', 'due_total' => '2450.00']);
    OrderItem::factory()->create(['order_id' => $order->id, 'quantity' => 1, 'line_total' => '2380.00']);
    OrderPlaced::dispatch($order->id, $this->me->id);

    $log = NotificationLog::query()->where('notifiable_id', $order->id)->sole();
    expect($log->channel)->toBe(NotificationChannel::Sms)
        ->and($log->status)->toBe(NotificationStatus::Sent)
        ->and($wa->sent)->toBe(0)
        ->and($sms->sent)->toBe(['+8801712345670']);
});
