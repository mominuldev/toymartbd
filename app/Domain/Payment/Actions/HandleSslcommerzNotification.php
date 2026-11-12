<?php

declare(strict_types=1);

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\DTOs\GatewayResult;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Exceptions\InvalidWebhookSignature;
use App\Domain\Payment\Gateways\Sslcommerz\SslcommerzCredentials;
use App\Domain\Payment\Gateways\Sslcommerz\SslcommerzGateway;
use App\Domain\Payment\Gateways\Sslcommerz\SslcommerzSignature;
use App\Domain\Payment\Jobs\ProcessSslcommerzReceipt;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\WebhookReceipt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * An SSLCommerz IPN, or the customer's browser returning to success/fail/cancel_url — both
 * carry the same signed POST (P4-PAY-03). In this order, without exception:
 *
 *   1. VERIFY the signature on the raw posted strings (SslcommerzSignature, the official
 *      library's algorithm). Invalid → 401, nothing parsed, nothing written (rule 19).
 *   2. Record a `webhook_receipts` row keyed on (sslcommerz, tran_id:status:val_id). A
 *      replay of a processed notification is a no-op.
 *   3. RE-QUERY the provider: the Order Validation API with the `val_id` for a VALID
 *      notification, else the query by tran_id. Only that answer moves money (rule 19).
 *      A FAILED/CANCELLED notification the re-query does not contradict closes the attempt
 *      (never as paid) so the customer can retry.
 *   4. ReconcileOnlinePayment applies it; a mismatch flags the payment for review.
 *
 * Steps 3–4 make HTTP calls, so they run outside any transaction (rule 4): queued for the
 * IPN (respond 200 fast), inline for the browser return (the customer waits for the answer).
 */
final readonly class HandleSslcommerzNotification
{
    public const string SOURCE_WEBHOOK = 'webhook';

    public const string SOURCE_RETURN = 'return';

    /** Headers worth keeping for a dispute — never Authorization, cookies or tokens. */
    private const array KEPT_HEADERS = ['user-agent', 'content-type', 'x-request-id'];

    public function __construct(
        private SslcommerzGateway $gateway,
        private ReconcileOnlinePayment $reconcile,
    ) {}

    /**
     * @param  array<string, mixed>  $post  the raw form fields as received
     * @param  array<string, string|null>  $headers
     * @return array{receipt: WebhookReceipt, payment: Payment|null, replayed: bool}
     *
     * @throws InvalidWebhookSignature
     */
    public function receive(array $post, string $source, array $headers = []): array
    {
        // 1. Signature first — before any parsing into domain objects or any write.
        $credentials = SslcommerzCredentials::resolve();
        if ($credentials === null || ! SslcommerzSignature::verify($post, $credentials->storePassword)) {
            Log::warning('payment.sslcommerz.signature_invalid', ['source' => $source]);

            throw InvalidWebhookSignature::make();
        }

        $tranId = self::field($post, 'tran_id');
        $valId = self::field($post, 'val_id');
        $status = strtoupper(self::field($post, 'status') ?? '');
        $eventId = Str::limit(($tranId ?? 'no-tran').':'.($status !== '' ? $status : 'NONE').':'.($valId ?? '-'), 191, '');

        // 2. Idempotency on the provider's event (UNIQUE provider + external_event_id).
        WebhookReceipt::query()->insertOrIgnore([[
            'provider' => SslcommerzGateway::PROVIDER,
            'external_event_id' => $eventId,
            'signature_valid' => true,
            'payload' => json_encode(self::storable($post), JSON_THROW_ON_ERROR),
            'headers' => json_encode(array_intersect_key(array_change_key_case($headers), array_flip(self::KEPT_HEADERS)), JSON_THROW_ON_ERROR),
            'source' => $source,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);
        /** @var WebhookReceipt $receipt */
        $receipt = WebhookReceipt::query()->where('provider', SslcommerzGateway::PROVIDER)->where('external_event_id', $eventId)->firstOrFail();
        $payment = $tranId === null ? null : self::payment($tranId);

        if ($receipt->processed_at !== null) {
            return ['receipt' => $receipt, 'payment' => $payment, 'replayed' => true];
        }

        if ($source === self::SOURCE_WEBHOOK) {
            ProcessSslcommerzReceipt::dispatch($receipt->id);
        } else {
            $this->process($receipt->id);
        }

        return ['receipt' => $receipt->refresh(), 'payment' => $payment?->refresh(), 'replayed' => false];
    }

    /**
     * Steps 3–4 for a recorded receipt. Safe to run twice: a processed receipt is skipped.
     * Returns the outcome; `deferred` (provider unreachable or unreadable) leaves the
     * receipt unprocessed so a retry — the job's, a repeated IPN, or the sweeper — tries again.
     */
    public function process(int $receiptId): string
    {
        $receipt = WebhookReceipt::query()->find($receiptId);
        if ($receipt === null || $receipt->processed_at !== null) {
            return 'skipped';
        }

        $post = $receipt->payload;
        $tranId = self::field($post, 'tran_id');
        $payment = $tranId === null ? null : self::payment($tranId);
        if ($payment === null) {
            self::finish($receipt, 'unknown_payment');

            return 'unknown_payment';
        }

        $posted = strtoupper(self::field($post, 'status') ?? '');
        $valId = self::field($post, 'val_id');

        // 3. The authoritative answer, from the provider — never from the body.
        $answer = $valId !== null && in_array($posted, ['VALID', 'VALIDATED'], true)
            ? $this->gateway->validate($payment, $valId)
            : $this->gateway->verify($payment);

        $unreadable = in_array($answer->payload['unchanged'] ?? null, ['gateway_unreachable', 'malformed_response'], true);
        if ($unreadable && ! in_array($posted, ['FAILED', 'CANCELLED', 'EXPIRED', 'UNATTEMPTED'], true)) {
            $receipt->forceFill(['result' => 'deferred', 'error' => (string) $answer->payload['unchanged']])->save();

            return 'deferred';
        }

        if ($answer->status === $payment->status && $payment->status->isOpen()
            && in_array($posted, ['FAILED', 'CANCELLED', 'EXPIRED', 'UNATTEMPTED'], true)) {
            // A signed failure the provider does not contradict: close the attempt (never as
            // paid) so the customer may retry with a new session.
            $answer = new GatewayResult(
                SslcommerzGateway::closedStatus($posted),
                failureReason: 'gateway_'.strtolower($posted),
                payload: ['driver' => SslcommerzGateway::PROVIDER, 'notification' => $posted] + $answer->payload,
            );
        }

        // 4. Apply (or flag).
        $result = $this->reconcile->handle($payment, $answer);
        self::finish($receipt, $result['outcome'], $result['reason']);

        return $result['outcome'];
    }

    private static function payment(string $tranId): ?Payment
    {
        return Payment::query()->where('transaction_id', $tranId)->where('method', PaymentMethod::Sslcommerz->value)->first();
    }

    private static function finish(WebhookReceipt $receipt, string $result, ?string $error = null): void
    {
        $receipt->forceFill(['processed_at' => now(), 'result' => $result, 'error' => $error === null ? null : Str::limit($error, 497)])->save();
    }

    /** @param  array<string, mixed>  $post */
    private static function field(array $post, string $key): ?string
    {
        $value = $post[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), 128, '') : null;
    }

    /**
     * The payload as kept for disputes: scalar fields only, bounded. SSLCommerz posts a
     * masked card number and no customer contact fields; nothing here is a credential.
     *
     * @param  array<string, mixed>  $post
     * @return array<string, string>
     */
    private static function storable(array $post): array
    {
        $kept = [];
        foreach (array_slice($post, 0, 80, true) as $key => $value) {
            if (is_scalar($value)) {
                $kept[Str::limit((string) $key, 64, '')] = Str::limit((string) $value, 500, '');
            }
        }

        return $kept;
    }
}
