<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Notification\Actions\SetNotificationPreference;
use App\Domain\Notification\Contracts\SmsGateway;
use App\Domain\Notification\Contracts\WhatsAppGateway;
use App\Domain\Notification\DTOs\SmsResult;
use App\Domain\Notification\DTOs\WhatsAppResult;
use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Jobs\SendNotification;
use App\Domain\Notification\Mail\OrderNotificationMail;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Ordering\Events\OrderCancelled;
use App\Domain\Ordering\Events\OrderConfirmed;
use App\Domain\Ordering\Events\OrderDelivered;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Support\Money;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * Order notifications end to end (P4-NOT-01/02/03, P4-I18N-03): WhatsApp first, SMS as
 * fallback, email alongside; per-message cost; opt-out respected; dead letters visible.
 */

/** A fake SMS driver that rejects (or accepts) and remembers what it was asked to send. */
final class NotificationFakeSms implements SmsGateway
{
    /** @var list<array{to: string, body: string, template: string}> */
    public array $sent = [];

    public function __construct(private readonly bool $accept = true) {}

    public function send(string $e164Phone, string $body, string $template): SmsResult
    {
        $this->sent[] = ['to' => $e164Phone, 'body' => $body, 'template' => $template];
        $segments = App\Domain\Notification\Support\SmsSegments::count($body);

        return $this->accept
            ? SmsResult::accepted('fake-1', $segments, Money::of('0.64')->times($segments))
            : SmsResult::rejected('insufficient balance', $segments);
    }
}

final class NotificationFakeWhatsApp implements WhatsAppGateway
{
    /** @var list<array{template: string, language: string, parameters: list<string>}> */
    public array $sent = [];

    public function __construct(private readonly bool $accept = true) {}

    public function sendTemplate(string $e164Phone, string $providerTemplate, string $language, array $parameters, string $template): WhatsAppResult
    {
        $this->sent[] = ['template' => $providerTemplate, 'language' => $language, 'parameters' => $parameters];

        return $this->accept ? WhatsAppResult::accepted('wamid.1', Money::of('0.45')) : WhatsAppResult::rejected('template not approved');
    }
}

function fakeSms(bool $accept = true): NotificationFakeSms
{
    $fake = new NotificationFakeSms($accept);
    // The `null` driver arm resolves NullSmsGateway from the container; stand the fake in.
    app()->bind(App\Domain\Notification\Drivers\NullSmsGateway::class, fn () => $fake);
    config(['notifications.sms.driver' => 'null']);

    return $fake;
}

function fakeWhatsApp(bool $accept = true): NotificationFakeWhatsApp
{
    $fake = new NotificationFakeWhatsApp($accept);
    app()->instance(WhatsAppGateway::class, $fake);

    return $fake;
}

function orderFor(array $customer = [], array $order = []): Order
{
    $c = Customer::factory()->create(['name' => 'নুসরাত জাহান', 'preferred_language' => 'bn', ...$customer]);
    $o = Order::factory()->create(['customer_id' => $c->id, 'grand_total' => '2450.00', 'due_total' => '2450.00', ...$order]);
    OrderItem::factory()->create(['order_id' => $o->id, 'quantity' => 2, 'line_total' => '2380.00']);

    return $o->refresh();
}

function logsFor(Order $order): Illuminate\Support\Collection
{
    return NotificationLog::query()->where('notifiable_id', $order->id)->orderBy('id')->get();
}

it('sends an order update over WhatsApp first, in Bangla, with its cost recorded', function (): void {
    $wa = fakeWhatsApp();
    $sms = fakeSms();
    $order = orderFor();

    OrderPlaced::dispatch($order->id, $order->customer_id);

    $log = logsFor($order)->sole();
    expect($log->channel)->toBe(NotificationChannel::WhatsApp)
        ->and($log->status)->toBe(NotificationStatus::Sent)
        ->and($log->locale)->toBe('bn')
        ->and($log->cost->toDecimal())->toBe('0.45')
        ->and($log->provider_message_id)->toBe('wamid.1')
        ->and($wa->sent[0])->toMatchArray(['template' => 'order_placed', 'language' => 'bn_BD', 'parameters' => ['নুসরাত জাহান', $order->number, '2', '2450']])
        ->and($sms->sent)->toBe([]);
});

it('never stores the full phone number in the log (rule 20)', function (): void {
    fakeWhatsApp();
    $order = orderFor();

    OrderPlaced::dispatch($order->id, $order->customer_id);

    $log = logsFor($order)->sole();
    $digits = preg_replace('/\D/', '', $order->shipping_phone);
    expect($log->recipient)->not->toContain(substr($digits, -8))
        ->and(json_encode($log->payload))->not->toContain(substr($digits, -8))
        ->and(json_encode($log->payload))->not->toContain($order->shipping_address)
        ->and($log->recipient_hash)->toBe(hash('sha256', $order->shipping_phone));
});

it('falls back to SMS when the customer turned WhatsApp off, counting Bangla segments', function (): void {
    $wa = fakeWhatsApp();
    $sms = fakeSms();
    $order = orderFor();
    app(SetNotificationPreference::class)->handle($order->customer_id, NotificationChannel::WhatsApp, NotificationCategory::OrderUpdates, false, 'admin');

    OrderPlaced::dispatch($order->id, $order->customer_id);

    $log = logsFor($order)->sole();
    expect($wa->sent)->toBe([])
        ->and($log->channel)->toBe(NotificationChannel::Sms)
        ->and($log->status)->toBe(NotificationStatus::Sent)
        ->and($log->encoding)->toBe('ucs2')
        ->and($log->segments)->toBe(2)
        ->and($log->cost->toDecimal())->toBe('1.28')
        ->and($sms->sent[0]['body'])->toContain('টয় মার্ট বিডি')->toContain($order->number)->toContain('BDT 2450');
});

it('records a skipped row and sends nothing when the customer opted out of every messaging channel', function (): void {
    $wa = fakeWhatsApp();
    $sms = fakeSms();
    $order = orderFor();
    foreach ([NotificationChannel::WhatsApp, NotificationChannel::Sms] as $channel) {
        app(SetNotificationPreference::class)->handle($order->customer_id, $channel, NotificationCategory::OrderUpdates, false, 'admin');
    }

    OrderConfirmed::dispatch($order->id, $order->customer_id, 'pending', 'confirmed');

    $log = logsFor($order)->sole();
    expect($log->status)->toBe(NotificationStatus::Skipped)
        ->and($log->error)->toBe('opted_out')
        ->and($wa->sent)->toBe([])
        ->and($sms->sent)->toBe([]);
});

it('refuses to let a customer opt out of security messages (OTP)', function (): void {
    $customer = Customer::factory()->create();

    expect(fn () => app(SetNotificationPreference::class)->handle($customer->id, NotificationChannel::Sms, NotificationCategory::Security, false, 'admin'))
        ->toThrow(InvalidArgumentException::class);
});

it('falls back to SMS once when the WhatsApp template send is rejected', function (): void {
    fakeWhatsApp(accept: false);
    $sms = fakeSms();
    $order = orderFor();

    OrderPlaced::dispatch($order->id, $order->customer_id);

    [$wa, $fallback] = logsFor($order)->all();
    expect($wa->status)->toBe(NotificationStatus::Failed)
        ->and($wa->error)->toBe('template not approved')
        ->and($fallback->channel)->toBe(NotificationChannel::Sms)
        ->and($fallback->fallback_of_id)->toBe($wa->id)
        ->and($fallback->status)->toBe(NotificationStatus::Sent)
        ->and($sms->sent)->toHaveCount(1);
});

it('dead-letters an SMS visibly after the last try, keeping the provider error', function (): void {
    config(['notifications.whatsapp.enabled' => false]);
    fakeSms(accept: false);
    Queue::fake();
    $order = orderFor();

    OrderPlaced::dispatch($order->id, $order->customer_id);
    // The listener is queued; run it, then the send job, by hand.
    app(App\Domain\Notification\Actions\QueueOrderNotification::class)->handle($order->id, 'order.placed');
    $log = logsFor($order)->sole();

    $job = new SendNotification($log->id);
    expect(fn () => app()->call([$job, 'handle']))->toThrow(App\Domain\Notification\Exceptions\NotificationSendFailed::class);
    expect($log->refresh()->status)->toBe(NotificationStatus::Queued)
        ->and($log->attempts)->toBe(1)
        ->and($log->error)->toBe('insufficient balance');

    $job->failed(new RuntimeException('SMS rejected: insufficient balance'));

    expect($log->refresh()->status)->toBe(NotificationStatus::DeadLettered)
        ->and($log->failed_at)->not->toBeNull()
        ->and($job->tries)->toBe(3)
        ->and($job->queue)->toBe('notify');
});

it('emails the customer in Bangla with the Bangla name, alongside the message', function (): void {
    Mail::fake();
    fakeWhatsApp();
    $order = orderFor(['email' => 'nusrat@example.com']);

    OrderPlaced::dispatch($order->id, $order->customer_id);

    $email = logsFor($order)->firstWhere('channel', NotificationChannel::Email);
    expect($email->status)->toBe(NotificationStatus::Sent)
        ->and($email->recipient)->toBe('n•••@example.com');

    Mail::assertSent(OrderNotificationMail::class, function (OrderNotificationMail $mail) use ($order): bool {
        $html = $mail->render();

        return $mail->hasTo('nusrat@example.com')
            && $mail->subjectLine === "অর্ডার {$order->number} পেয়েছি - টয় মার্ট বিডি"
            && str_contains($html, 'নুসরাত জাহান')
            && str_contains($html, 'কাঠের পাজল সেট')
            && str_contains($html, 'lang="bn"')
            && str_contains($html, 'Anek Bangla');
    });
});

it('emails confirmed, delivered and cancelled in English for an English customer', function (): void {
    Mail::fake();
    fakeWhatsApp();
    $order = orderFor(['email' => 'rafi@example.com', 'preferred_language' => 'en', 'name' => 'Rafi Ahmed'], ['shipping_name' => 'Rafi Ahmed', 'delivered_at' => now()]);

    OrderConfirmed::dispatch($order->id, $order->customer_id, 'pending', 'confirmed');
    OrderDelivered::dispatch($order->id, $order->customer_id, 'shipped', 'delivered');
    OrderCancelled::dispatch($order->id, $order->customer_id, 'pending', 'customer_request', null, null, '0.00');

    Mail::assertSent(OrderNotificationMail::class, 3);
    Mail::assertSent(OrderNotificationMail::class, fn (OrderNotificationMail $m): bool => $m->subjectLine === "Order {$order->number} confirmed" && str_contains($m->render(), 'Hello Rafi Ahmed'));
    Mail::assertSent(OrderNotificationMail::class, fn (OrderNotificationMail $m): bool => $m->subjectLine === "Delivered - order {$order->number}");
    Mail::assertSent(OrderNotificationMail::class, fn (OrderNotificationMail $m): bool => $m->subjectLine === "Order {$order->number} cancelled");
});

it('skips email when the customer opted out of it, without touching messaging', function (): void {
    Mail::fake();
    fakeWhatsApp();
    $order = orderFor(['email' => 'nusrat@example.com']);
    app(SetNotificationPreference::class)->handle($order->customer_id, NotificationChannel::Email, NotificationCategory::OrderUpdates, false, 'admin');

    OrderPlaced::dispatch($order->id, $order->customer_id);

    expect(logsFor($order)->firstWhere('channel', NotificationChannel::Email)->status)->toBe(NotificationStatus::Skipped)
        ->and(logsFor($order)->firstWhere('channel', NotificationChannel::WhatsApp)->status)->toBe(NotificationStatus::Sent);
    Mail::assertNothingSent();
});

it('does not send a template an admin switched off', function (): void {
    $wa = fakeWhatsApp();
    $sms = fakeSms();
    NotificationTemplate::query()->where('key', 'order.placed')->update(['is_active' => false]);
    $order = orderFor();

    OrderPlaced::dispatch($order->id, $order->customer_id);

    expect(logsFor($order))->toHaveCount(0)->and($wa->sent)->toBe([])->and($sms->sent)->toBe([]);
});

it('records a direct SMS send (the OTP job) with its segments and cost', function (): void {
    config(['notifications.sms.driver' => 'log', 'notifications.sms.cost_per_segment' => '0.64']);

    $result = app(SmsGateway::class)->send('+8801712345678', 'টয় মার্ট বিডি কোড: 482917। 5 মিনিট কাজ করবে। কাউকে দেবেন না।', 'otp.login');

    $log = NotificationLog::query()->sole();
    expect($result->accepted)->toBeTrue()
        ->and($result->segments)->toBe(1)
        ->and($log->category)->toBe(NotificationCategory::Security)
        ->and($log->locale)->toBe('bn')
        ->and($log->cost->toDecimal())->toBe('0.64')
        ->and($log->recipient)->not->toContain('12345678')
        ->and($log->payload)->toBeNull();
});
