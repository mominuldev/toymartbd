<?php

declare(strict_types=1);

use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Actions\HandleSslcommerzNotification;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Jobs\ProcessSslcommerzReceipt;
use App\Domain\Payment\Models\WebhookReceipt;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Feature\Orders\Support;
use Tests\Feature\Payment\Sslcommerz\Fixtures;

/*
 * Phase 9 coverage (docs/audits/SEO-AND-COVERAGE.md, Task B): the IPN path when SSLCommerz's
 * validation API cannot be reached. A verified IPN is never applied from its body (rule 19):
 * the receipt stays open as `deferred`, the queued job retries on its backoff schedule, gives
 * up after its last try (the sweeper/poll owns it then), and a later successful re-query
 * marks the payment paid exactly once.
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    $this->token = CartTestKit::storefrontToken();
    config(['risk.first_order_hold_threshold' => '100000.00']);
    Fixtures::configure();
    $this->variant = CartTestKit::variant('900.00', 5);

    $cart = CheckoutKit::cart([$this->variant->id => 2]);
    $this->withToken($this->token)->postJson('/api/v1/storefront/checkout', CheckoutKit::payload($cart, ['payment_method' => 'sslcommerz']), [
        CartTestKit::HEADER => $cart,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->assertCreated();
    $this->order = Order::query()->latest('id')->firstOrFail();

    Http::fake([Fixtures::SANDBOX.'/gwprocess/v4/api.php' => Http::response(Fixtures::load('session.success'))]);
    $this->withToken($this->token)->postJson("/api/v1/storefront/orders/{$this->order->number}/payment", [], [
        CartTestKit::HEADER => $cart,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->assertOk();
    $this->payment = Fixtures::initiatedPayment($this->order);
    $this->values = Fixtures::values($this->payment);
});

/** The validator times out while `$up` is false, then answers VALID (Http::fake stubs stack, so one stub decides). */
function ipnRetryValidatorDown(?Closure $up = null, array $values = []): void
{
    Http::fake([Fixtures::SANDBOX.'/validator/*' => function () use ($up, $values) {
        if ($up !== null && $up()) {
            return Http::response(Fixtures::load('validation.valid', $values));
        }
        throw new ConnectionException('timed out');
    }]);
}

it('leaves a verified IPN open as deferred when the validation API is unreachable — never paid from the body', function (): void {
    ipnRetryValidatorDown();

    $this->post('/api/v1/webhooks/payments/sslcommerz/ipn', Fixtures::signedNotification('ipn.valid', $this->values), ['Accept' => 'application/json'])
        ->assertOk();

    $receipt = WebhookReceipt::query()->sole();
    expect($receipt->processed_at)->toBeNull()
        ->and($receipt->result)->toBe('deferred')
        ->and($receipt->error)->toBe('gateway_unreachable')
        ->and($this->payment->refresh()->status)->toBe(PaymentStatus::Initiated)
        ->and($this->order->refresh()->payment_status)->not->toBe(OrderPaymentStatus::Paid);
    Support::assertStockInvariants();
});

it('releases the job on its backoff schedule while deferred, and stops after the last try', function (int $attempt, ?int $delay): void {
    ipnRetryValidatorDown();
    $this->post('/api/v1/webhooks/payments/sslcommerz/ipn', Fixtures::signedNotification('ipn.valid', $this->values), ['Accept' => 'application/json'])->assertOk();

    $job = (new ProcessSslcommerzReceipt(WebhookReceipt::query()->sole()->id))->withFakeQueueInteractions();
    $job->job->attempts = $attempt;
    $job->handle(app(HandleSslcommerzNotification::class));

    if ($delay === null) {
        $job->assertNotReleased();
    } else {
        $job->assertReleased($delay);
    }
    expect(WebhookReceipt::query()->sole()->processed_at)->toBeNull();
})->with([
    'first try → 10 s' => [1, 10],
    'second try → 30 s' => [2, 30],
    'fourth try → 120 s' => [4, 120],
    'fifth (last) try → not released' => [5, null],
]);

it('applies the payment once the validation API answers on a retry, and a later retry is a no-op', function (): void {
    $up = false;
    ipnRetryValidatorDown(function () use (&$up): bool {
        return $up;
    }, $this->values);
    $this->post('/api/v1/webhooks/payments/sslcommerz/ipn', Fixtures::signedNotification('ipn.valid', $this->values), ['Accept' => 'application/json'])->assertOk();
    $receiptId = WebhookReceipt::query()->sole()->id;
    expect(WebhookReceipt::query()->sole()->result)->toBe('deferred');

    $up = true;
    $job = (new ProcessSslcommerzReceipt($receiptId))->withFakeQueueInteractions();
    $job->handle(app(HandleSslcommerzNotification::class));

    $job->assertNotReleased();
    expect($this->payment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($this->order->refresh()->payment_status)->toBe(OrderPaymentStatus::Paid)
        ->and(WebhookReceipt::query()->sole()->processed_at)->not->toBeNull()
        ->and(WebhookReceipt::query()->sole()->result)->toBe('applied');

    expect(app(HandleSslcommerzNotification::class)->process($receiptId))->toBe('skipped');
    Support::assertStockInvariants();
    Support::assertOrderFinancials($this->order);
});
