<?php

declare(strict_types=1);

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\DTOs\GatewayResult;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Gateways\Bkash\BkashGateway;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\WebhookReceipt;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The customer's browser came back from bKash to our callbackURL, which bKash suffixes with
 * `paymentID`, `status` (`success` | `failure` | `cancel`) and `signature`.
 *
 * bKash publishes NO way to verify that `signature` (docs/_research/bkash-api.md §5), so the
 * callback is never authoritative: like a Pathao webhook (rule 24c) it is only a TRIGGER, and
 * only bKash's Query Payment answer moves money (rule 19). In this order, without exception:
 *
 *   1. The paymentID must be one of OUR online bKash payments, for the order named — the caller
 *      looks it up and refuses an unknown one (404) before anything is written.
 *   2. Record a `webhook_receipts` row (signature_valid = false: there is nothing to check)
 *      keyed on paymentID + reported status. A replayed callback is a no-op.
 *   3. Under a per-payment lock, QUERY bKash. `Completed` → apply it (done; never executed
 *      twice). Still `Initiated`:
 *        - status `success` → EXECUTE (the capture; the storefront route that triggers it
 *          requires an Idempotency-Key, rule 17), then QUERY AGAIN and apply only that answer.
 *          If Execute was definitely refused and the query still says Initiated, the attempt
 *          is closed `failed` — bKash spends a paymentId on any execute attempt. A timeout is
 *          left open for a retry or the sweeper.
 *        - status `failure` / `cancel` → the attempt is closed `failed` / `cancelled`. That
 *          writes no money and only ends this attempt: a retry opens a new one, and if bKash
 *          ever reports it Completed, PaymentTransitions refuses paid-after-close and flags
 *          the payment for a human.
 *   4. ReconcileOnlinePayment applies every answer: an amount or currency mismatch is
 *      flagged for review, never paid; the ledger is untouched here (revenue is recognised
 *      on delivery, rule 10).
 *
 * Every bKash call happens outside any transaction (rule 4). Inline, because the customer
 * is waiting on the page; there is no bKash IPN listener (its SNS signature scheme is
 * undocumented — the research doc), so the sweeper's query is the safety net.
 */
final readonly class HandleBkashCallback
{
    public const string SOURCE = 'return';

    /** The statuses bKash documents on the callback. */
    public const array STATUSES = ['success', 'failure', 'cancel'];

    /** Query answers that mean "ask again later": the receipt stays unprocessed. */
    private const array NOT_YET = ['gateway_unreachable', 'malformed_response', 'token_unavailable', 'credentials_rejected', 'gateway_error'];

    /** Headers worth keeping for a dispute — never Authorization, cookies or tokens. */
    private const array KEPT_HEADERS = ['user-agent', 'content-type', 'x-request-id'];

    public function __construct(
        private BkashGateway $gateway,
        private ReconcileOnlinePayment $reconcile,
    ) {}

    /** Our online bKash payment for this order and paymentID, or null (the caller answers 404). */
    public static function payment(int $orderId, string $paymentId): ?Payment
    {
        $payment = Payment::query()
            ->where('order_id', $orderId)
            ->where('method', PaymentMethod::Bkash->value)
            ->where('transaction_id', $paymentId)
            ->first();

        return $payment !== null && BkashGateway::isOnlinePayment($payment) ? $payment : null;
    }

    /**
     * @param  array<string, mixed>  $fields  what arrived (kept for disputes, never trusted)
     * @param  array<string, string|null>  $headers
     * @return array{receipt: WebhookReceipt, payment: Payment, replayed: bool, outcome: string}
     */
    public function receive(Payment $payment, string $status, array $fields, array $headers = []): array
    {
        $eventId = Str::limit(self::SOURCE.':'.$payment->transaction_id.':'.$status, 191, '');

        WebhookReceipt::query()->insertOrIgnore([[
            'provider' => BkashGateway::PROVIDER,
            'external_event_id' => $eventId,
            'signature_valid' => false,
            'payload' => json_encode(['payment_id' => $payment->transaction_id, 'status' => $status] + self::storable($fields), JSON_THROW_ON_ERROR),
            'headers' => json_encode(array_intersect_key(array_change_key_case($headers), array_flip(self::KEPT_HEADERS)), JSON_THROW_ON_ERROR),
            'source' => self::SOURCE,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);
        /** @var WebhookReceipt $receipt */
        $receipt = WebhookReceipt::query()->where('provider', BkashGateway::PROVIDER)->where('external_event_id', $eventId)->firstOrFail();

        if ($receipt->processed_at !== null) {
            return ['receipt' => $receipt, 'payment' => $payment->refresh(), 'replayed' => true, 'outcome' => (string) $receipt->result];
        }

        $outcome = $this->process($receipt->id);

        return ['receipt' => $receipt->refresh(), 'payment' => $payment->refresh(), 'replayed' => false, 'outcome' => $outcome];
    }

    /** Step 3–4 for a recorded receipt. Safe to run twice: a processed receipt is skipped. */
    public function process(int $receiptId): string
    {
        $receipt = WebhookReceipt::query()->find($receiptId);
        if ($receipt === null || $receipt->processed_at !== null) {
            return 'skipped';
        }
        $paymentId = is_string($receipt->payload['payment_id'] ?? null) ? $receipt->payload['payment_id'] : null;
        $status = is_string($receipt->payload['status'] ?? null) ? $receipt->payload['status'] : null;
        $payment = $paymentId === null ? null : Payment::query()->where('method', PaymentMethod::Bkash->value)->where('transaction_id', $paymentId)->first();
        if ($payment === null || ! BkashGateway::isOnlinePayment($payment) || ! in_array($status, self::STATUSES, true)) {
            self::finish($receipt, 'unknown_payment');

            return 'unknown_payment';
        }

        try {
            // One execute per payment, however many callbacks or retries race (bKash spends the
            // paymentId on the first execute attempt).
            return Cache::lock('payments:bkash:callback:'.$payment->id, 90)->block(45, fn (): string => $this->settle($receipt, $payment, $status));
        } catch (LockTimeoutException) {
            $receipt->forceFill(['result' => 'deferred', 'error' => 'busy'])->save();

            return 'deferred';
        }
    }

    private function settle(WebhookReceipt $receipt, Payment $payment, string $status): string
    {
        $payment->refresh();
        $answer = $this->gateway->verify($payment);
        $unchanged = $answer->payload['unchanged'] ?? null;

        if ($unchanged === 'initiated' && $payment->status === PaymentStatus::Initiated) {
            if ($status !== 'success') {
                $closed = $status === 'cancel' ? PaymentStatus::Cancelled : PaymentStatus::Failed;

                return $this->apply($receipt, $payment, self::close($payment, $closed, $status === 'cancel' ? 'customer_cancelled' : 'gateway_failed'));
            }

            $executed = $this->gateway->execute($payment);
            $answer = $this->gateway->verify($payment);
            $unchanged = $answer->payload['unchanged'] ?? null;
            if ($unchanged === 'initiated' && $executed['outcome'] === 'refused') {
                return $this->apply($receipt, $payment, self::close($payment, PaymentStatus::Failed, 'execute_refused:'.$executed['code']));
            }
            if ($unchanged === 'initiated' && $executed['outcome'] === 'unknown') {
                $receipt->forceFill(['result' => 'deferred', 'error' => 'execute_'.($executed['code'] ?? 'unknown')])->save();

                return 'deferred';
            }
        }

        if (is_string($unchanged) && in_array($unchanged, self::NOT_YET, true)) {
            $receipt->forceFill(['result' => 'deferred', 'error' => $unchanged])->save();

            return 'deferred';
        }

        return $this->apply($receipt, $payment, $answer);
    }

    private function apply(WebhookReceipt $receipt, Payment $payment, GatewayResult $answer): string
    {
        $result = $this->reconcile->handle($payment, $answer);
        $unchanged = $answer->payload['unchanged'] ?? null;
        self::finish($receipt, $result['outcome'], $result['reason'] ?? (is_string($unchanged) ? $unchanged : null));

        return $result['outcome'];
    }

    /** Close an attempt bKash still reports Initiated. Keeps the driver marker and our invoice. */
    private static function close(Payment $payment, PaymentStatus $to, string $reason): GatewayResult
    {
        return new GatewayResult($to, failureReason: Str::limit($reason, 60, ''), payload: [
            'driver' => BkashGateway::PROVIDER,
            'merchant_invoice' => $payment->gateway_payload['merchant_invoice'] ?? null,
            'payment_id' => $payment->transaction_id,
            'closed_by' => 'callback',
        ]);
    }

    private static function finish(WebhookReceipt $receipt, string $result, ?string $error = null): void
    {
        $receipt->forceFill(['processed_at' => now(), 'result' => $result, 'error' => $error === null ? null : Str::limit($error, 497)])->save();
    }

    /**
     * Scalar fields only, bounded; never a wallet number (rule 20).
     *
     * @param  array<array-key, mixed>  $fields
     * @return array<string, string>
     */
    private static function storable(array $fields): array
    {
        $kept = [];
        foreach (array_slice($fields, 0, 20, true) as $key => $value) {
            if (is_scalar($value) && ! in_array(strtolower((string) $key), ['payeraccount', 'customermsisdn', 'msisdn', 'phone'], true)) {
                $kept[Str::limit((string) $key, 64, '')] = Str::limit((string) $value, 500, '');
            }
        }

        return $kept;
    }
}
