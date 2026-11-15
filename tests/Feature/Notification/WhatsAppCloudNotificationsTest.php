<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Inbox\Enums\DeliveryStatus;
use App\Domain\Inbox\Enums\MessageDirection;
use App\Domain\Inbox\Enums\MessageKind;
use App\Domain\Inbox\Models\ConversationMessage;
use App\Domain\Notification\Contracts\WhatsAppCloudAccount;
use App\Domain\Notification\Contracts\WhatsAppGateway;
use App\Domain\Notification\Drivers\CloudWhatsAppGateway;
use App\Domain\Notification\Drivers\LogWhatsAppGateway;
use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Enums\WhatsAppDeliveryStatus;
use App\Domain\Notification\Jobs\SendNotification;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Notification\Services\WhatsAppDrivers;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Models\Enums\Role;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Inbox\InboxFixtures;

/*
 * Order updates over the WhatsApp Cloud API (the `cloud` notification driver). UNVERIFIED: no
 * WhatsApp Business account exists (2026-11-16) — Meta's request/response shapes here are from
 * its published docs (docs/api/inbox.md), faked with Http::fake. One WhatsApp number: the
 * driver uses the Inbox's credentials, and its delivery statuses arrive at the Inbox's webhook.
 */

const WAC_TOKEN = 'EAAG-cloud-notify-token-TEST-4242';
const WAC_PHONE_ID = '106540352242922';
const WAC_SEND_URL = 'https://graph.facebook.com/v27.0/106540352242922/messages';

beforeEach(function (): void {
    Http::preventStrayRequests();
    InboxFixtures::configure();
    config([
        'inbox.whatsapp.phone_number_id' => WAC_PHONE_ID,
        'inbox.whatsapp.access_token' => WAC_TOKEN,
        'inbox.graph_base_url' => 'https://graph.facebook.com',
        'inbox.graph_version' => 'v27.0',
        'notifications.whatsapp.driver' => 'cloud',
        'notifications.whatsapp.enabled' => true,
        'notifications.whatsapp.cost_per_message' => '0.45',
        'notifications.sms.driver' => 'log',
    ]);
});

function wacOrder(): Order
{
    $customer = Customer::factory()->create(['name' => 'নুসরাত জাহান', 'preferred_language' => 'bn']);
    $order = Order::factory()->create(['customer_id' => $customer->id, 'grand_total' => '2450.00', 'due_total' => '2450.00']);
    OrderItem::factory()->create(['order_id' => $order->id, 'quantity' => 2, 'line_total' => '2380.00']);

    return $order->refresh();
}

function wacLog(Order $order): NotificationLog
{
    return NotificationLog::query()->where('notifiable_id', $order->id)->where('channel', NotificationChannel::WhatsApp->value)->sole();
}

it('resolves the cloud driver from config, and the admin setting over config', function (): void {
    expect(app(WhatsAppGateway::class))->toBeInstanceOf(CloudWhatsAppGateway::class);

    Setting::put('messaging.whatsapp_driver', 'log', SettingGroup::Notification);
    expect(app(WhatsAppGateway::class))->toBeInstanceOf(LogWhatsAppGateway::class)
        ->and(app(WhatsAppDrivers::class)->name())->toBe('log');
});

it('sends an approved utility template with the Inbox number and keeps Meta\'s message id', function (): void {
    Http::fake([WAC_SEND_URL => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['input' => '8801841523907', 'wa_id' => '8801841523907']], 'messages' => [['id' => 'wamid.HBgNODgwMTg0MTUyMzkwNxUCABEYEjQ1']]])]);
    $order = wacOrder();

    OrderPlaced::dispatch($order->id, $order->customer_id);

    $log = wacLog($order);
    expect($log->status)->toBe(NotificationStatus::Sent)
        ->and($log->provider)->toBe('cloud')
        ->and($log->provider_message_id)->toBe('wamid.HBgNODgwMTg0MTUyMzkwNxUCABEYEjQ1')
        ->and($log->cost->toDecimal())->toBe('0.45');

    Http::assertSent(function (Request $request) use ($order): bool {
        $template = $request['template'];

        return $request->url() === WAC_SEND_URL
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer '.WAC_TOKEN)
            && $request['messaging_product'] === 'whatsapp'
            && $request['type'] === 'template'
            && $request['to'] === ltrim((string) App\Support\PhoneNumber::parse($order->shipping_phone)->e164(), '+')
            && $template['name'] === 'order_placed'
            && $template['language'] === ['code' => 'bn_BD']
            && $template['components'][0]['type'] === 'body'
            && $template['components'][0]['parameters'][0] === ['type' => 'text', 'text' => 'নুসরাত জাহান']
            && $template['components'][0]['parameters'][1] === ['type' => 'text', 'text' => $order->number];
    });
});

it('returns a provider refusal as a failed send and falls back to SMS once, without logging the number', function (): void {
    Http::fake([WAC_SEND_URL => Http::response(['error' => ['message' => '(#132001) Template name does not exist in the translation', 'type' => 'OAuthException', 'code' => 132001, 'fbtrace_id' => 'Abc']], 400)]);
    $logged = [];
    Log::listen(function ($message) use (&$logged): void {
        $logged[] = json_encode([$message->message, $message->context]);
    });
    $order = wacOrder();

    OrderPlaced::dispatch($order->id, $order->customer_id);

    $log = wacLog($order);
    $digits = preg_replace('/\D/', '', $order->shipping_phone);
    expect($log->status)->toBe(NotificationStatus::Failed)
        ->and($log->error)->toContain('provider_error:132001')
        ->and(NotificationLog::query()->where('fallback_of_id', $log->id)->sole()->channel)->toBe(NotificationChannel::Sms)
        ->and(implode("\n", $logged))->not->toContain(substr((string) $digits, -8))
        ->and(implode("\n", $logged))->not->toContain(WAC_TOKEN);
});

it('throws on a 5xx so the job retries, and refuses without credentials without calling Meta', function (): void {
    Http::fake([WAC_SEND_URL => Http::response(['error' => ['code' => 2]], 503)]);
    $gateway = app(CloudWhatsAppGateway::class);

    expect(fn () => $gateway->sendTemplate('+8801841523907', 'order_placed', 'en', ['A'], 'order_placed'))->toThrow(RuntimeException::class);

    config(['inbox.whatsapp.access_token' => null]);
    $result = app(CloudWhatsAppGateway::class)->sendTemplate('+8801841523907', 'order_placed', 'en', ['A'], 'order_placed');
    expect($result->accepted)->toBeFalse()->and($result->error)->toBe('not_configured');
    Http::assertSentCount(1);
});

it('reads the credentials the Inbox stores — one WhatsApp number, one token', function (): void {
    Setting::put('inbox.whatsapp.phone_number_id', '999000111', SettingGroup::Notification);
    Setting::put('inbox.whatsapp.access_token', 'EAAG-saved-in-admin-0001', SettingGroup::Notification, true);
    Setting::put('inbox.whatsapp.business_account_id', '555000222', SettingGroup::Notification);

    $credentials = app(WhatsAppCloudAccount::class)->credentials();

    expect($credentials->phoneNumberId)->toBe('999000111')
        ->and($credentials->accessToken)->toBe('EAAG-saved-in-admin-0001')
        ->and($credentials->businessAccountId)->toBe('555000222')
        ->and($credentials->url('999000111/messages'))->toBe('https://graph.facebook.com/v27.0/999000111/messages');
});

/* ---- Delivery statuses from the Inbox webhook ------------------------------------------- */

function wacSentLog(string $wamid): NotificationLog
{
    return NotificationLog::query()->create([
        'channel' => NotificationChannel::WhatsApp,
        'template' => 'order.placed',
        'category' => NotificationCategory::OrderUpdates,
        'locale' => 'bn',
        'recipient' => '017****3907',
        'notifiable_type' => NotificationLog::NOTIFIABLE_ORDER,
        'notifiable_id' => wacOrder()->id,
        'payload' => ['customer_name' => 'নুসরাত'],
        'status' => NotificationStatus::Sent,
        'provider' => 'cloud',
        'provider_message_id' => $wamid,
        'queued_at' => now(),
        'sent_at' => now(),
    ]);
}

it('applies delivered then read to the notification log, forward only', function (): void {
    $log = wacSentLog('wamid.NOTIFY0001');

    InboxFixtures::post($this, 'whatsapp', InboxFixtures::body('whatsapp-status', ['__WAMID__' => 'wamid.NOTIFY0001']))->assertOk();

    $log->refresh();
    expect($log->delivery_status)->toBe(WhatsAppDeliveryStatus::Read)
        ->and($log->delivered_at?->getTimestamp())->toBe(1763190600)
        ->and($log->read_at?->getTimestamp())->toBe(1763190660)
        ->and($log->status)->toBe(NotificationStatus::Sent);

    // A late failure (or a replay of `delivered`) never moves it back.
    InboxFixtures::post($this, 'whatsapp', InboxFixtures::body('whatsapp-status-failed', ['__WAMID__' => 'wamid.NOTIFY0001']))->assertOk();
    expect($log->refresh()->delivery_status)->toBe(WhatsAppDeliveryStatus::Read)
        ->and(NotificationLog::query()->where('fallback_of_id', $log->id)->exists())->toBeFalse();
});

it('shows the delivery status on the admin message log', function (): void {
    $log = wacSentLog('wamid.NOTIFY0003');
    InboxFixtures::post($this, 'whatsapp', InboxFixtures::body('whatsapp-status', ['__WAMID__' => 'wamid.NOTIFY0003']))->assertOk();
    wacAdmin();

    $row = collect($this->getJson('/api/v1/admin/notifications/logs')->assertOk()->json('data'))->firstWhere('id', $log->id);
    expect($row)->toMatchArray(['status' => 'sent', 'delivery_status' => 'read', 'delivery_error' => null])
        ->and($row['read_at'])->not->toBeNull();
});

it('records a failed delivery with Meta\'s error and queues the SMS fallback once', function (): void {
    Queue::fake([SendNotification::class]);
    $log = wacSentLog('wamid.NOTIFY0002');

    InboxFixtures::post($this, 'whatsapp', InboxFixtures::body('whatsapp-status-failed', ['__WAMID__' => 'wamid.NOTIFY0002']))->assertOk();
    InboxFixtures::post($this, 'whatsapp', InboxFixtures::body('whatsapp-status-failed', ['__WAMID__' => 'wamid.NOTIFY0002']))->assertOk();

    $log->refresh();
    expect($log->delivery_status)->toBe(WhatsAppDeliveryStatus::Failed)
        ->and($log->delivery_error)->toStartWith('provider_error:')
        ->and(NotificationLog::query()->where('fallback_of_id', $log->id)->count())->toBe(1);
    Queue::assertPushed(SendNotification::class, 1);
});

it('still applies statuses to inbox messages, and never raises the event for them', function (): void {
    Illuminate\Support\Facades\Event::fake([App\Domain\Inbox\Events\WhatsAppMessageStatusReceived::class]);
    $conversation = InboxFixtures::conversation();
    $message = ConversationMessage::query()->create([
        'conversation_id' => $conversation->id, 'channel' => $conversation->channel, 'direction' => MessageDirection::Out,
        'kind' => MessageKind::Text, 'body' => 'Apa, ami Rina.', 'delivery_status' => DeliveryStatus::Sent,
        'provider_message_id' => 'wamid.INBOX0001', 'sent_at' => now()->subMinute(),
    ]);

    InboxFixtures::post($this, 'whatsapp', InboxFixtures::body('whatsapp-status', ['__WAMID__' => 'wamid.INBOX0001']))->assertOk();
    InboxFixtures::post($this, 'whatsapp', InboxFixtures::body('whatsapp-status', ['__WAMID__' => 'wamid.UNKNOWN01']))->assertOk();

    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Read);
    Illuminate\Support\Facades\Event::assertDispatchedTimes(App\Domain\Inbox\Events\WhatsAppMessageStatusReceived::class, 2);
    Illuminate\Support\Facades\Event::assertDispatched(
        App\Domain\Inbox\Events\WhatsAppMessageStatusReceived::class,
        fn ($e): bool => $e->providerMessageId === 'wamid.UNKNOWN01',
    );
    Illuminate\Support\Facades\Event::assertNotDispatched(
        App\Domain\Inbox\Events\WhatsAppMessageStatusReceived::class,
        fn ($e): bool => $e->providerMessageId === 'wamid.INBOX0001',
    );
});

/* ---- Admin → Integrations → Messaging: the driver switch ------------------------------- */

function wacAdmin(): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole(Role::SuperAdmin)->create()->refresh();
    test()->actingAs($user, 'web');

    return $user;
}

/** @return array<string, mixed> */
function wacMessaging(array $overrides = []): array
{
    return ['sms_driver' => 'log', 'sms_cost_per_segment' => '0.26', 'whatsapp_enabled' => false, ...$overrides];
}

it('lets the admin pick the cloud WhatsApp driver, off by default, only once the Inbox number is connected', function (): void {
    config(['notifications.whatsapp.driver' => 'log']);
    wacAdmin();

    $this->getJson('/api/v1/admin/settings/messaging')->assertOk()
        ->assertJsonPath('whatsapp_driver', 'log')
        ->assertJsonPath('sources.whatsapp_driver', 'env');

    config(['inbox.whatsapp.access_token' => null]);
    $this->putJson('/api/v1/admin/settings/messaging', wacMessaging(['whatsapp_driver' => 'cloud']))
        ->assertUnprocessable()
        ->assertJsonFragment(['field' => 'whatsapp_driver']);
    $this->putJson('/api/v1/admin/settings/messaging', wacMessaging(['whatsapp_driver' => 'null']))
        ->assertUnprocessable()
        ->assertJsonFragment(['field' => 'whatsapp_driver']);

    config(['inbox.whatsapp.access_token' => WAC_TOKEN]);
    $this->putJson('/api/v1/admin/settings/messaging', wacMessaging(['whatsapp_driver' => 'cloud']))
        ->assertOk()
        ->assertJsonPath('whatsapp_driver', 'cloud')
        ->assertJsonPath('sources.whatsapp_driver', 'settings');
    expect(app(WhatsAppGateway::class))->toBeInstanceOf(CloudWhatsAppGateway::class);

    // An older client that does not send the field keeps the saved driver.
    $this->putJson('/api/v1/admin/settings/messaging', wacMessaging())->assertOk()->assertJsonPath('whatsapp_driver', 'cloud');
});
