<?php

declare(strict_types=1);

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Gateways\Paystation\PaystationGateway;
use App\Domain\Payment\Jobs\ProcessPaystationReceipt;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\WebhookReceipt;
use Illuminate\Support\Str;

/**
 * A PayStation IPN, or the customer's browser returning to our callback_url.
 *
 * PayStation documents NO signature on either (docs/_research/paystation-api.md §5–6), so
 * neither is ever authoritative: like a Pathao webhook (rule 24c) each is only a TRIGGER
 * to re-query the transaction-status API, and that answer alone moves money (rule 19).
 * In this order, without exception:
 *
 *   1. The invoice must be one of OUR PayStation payments — the caller looks it up and
 *      refuses an unknown one (404) before anything is written.
 *   2. Record a `webhook_receipts` row (signature_valid = false: there is none to check)
 *      keyed on the source and invoice. A replayed IPN is a no-op; browser returns are
 *      bucketed per minute so a refresh re-queries at most once a minute.
 *   3. RE-QUERY PayStation by our invoice number. 4. ReconcileOnlinePayment applies it; an
 *      amount mismatch flags the payment for review, never paid.
 *
 * Steps 3–4 make HTTP calls, so they run outside any transaction (rule 4): queued for the
 * IPN (respond 200 fast), inline for the browser return (the customer waits).
 */
final readonly class HandlePaystationNotification
{
    public const string SOURCE_WEBHOOK = 'webhook';

    public const string SOURCE_RETURN = 'return';

    /** Headers worth keeping for a dispute — never Authorization, cookies or tokens. */
    private const array KEPT_HEADERS = ['user-agent', 'content-type', 'x-request-id'];

    /** Re-query answers that say "not settled yet": an IPN retries on these. */
    private const array NOT_YET = ['gateway_unreachable', 'malformed_response', 'not_found', 'processing'];

    public function __construct(
        private PaystationGateway $gateway,
        private ReconcileOnlinePayment $reconcile,
    ) {}

    /** Our PayStation payment for an invoice number, or null (the caller answers 404). */
    public static function payment(string $invoiceNumber): ?Payment
    {
        return Payment::query()
            ->where('transaction_id', $invoiceNumber)
            ->where('method', PaymentMethod::Paystation->value)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $fields  what arrived (kept for disputes, never trusted)
     * @param  array<string, string|null>  $headers
     * @return array{receipt: WebhookReceipt, payment: Payment, replayed: bool}
     */
    public function receive(Payment $payment, array $fields, string $source, array $headers = []): array
    {
        $invoice = (string) $payment->transaction_id;
        $suffix = $source === self::SOURCE_WEBHOOK
            ? (self::field($fields, 'trx_id') ?? '-')
            : now()->format('YmdHi');
        $eventId = Str::limit($source.':'.$invoice.':'.$suffix, 191, '');

        WebhookReceipt::query()->insertOrIgnore([[
            'provider' => PaystationGateway::PROVIDER,
            'external_event_id' => $eventId,
            'signature_valid' => false,
            'payload' => json_encode(['invoice_number' => $invoice] + self::storable($fields), JSON_THROW_ON_ERROR),
            'headers' => json_encode(array_intersect_key(array_change_key_case($headers), array_flip(self::KEPT_HEADERS)), JSON_THROW_ON_ERROR),
            'source' => $source,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);
        /** @var WebhookReceipt $receipt */
        $receipt = WebhookReceipt::query()->where('provider', PaystationGateway::PROVIDER)->where('external_event_id', $eventId)->firstOrFail();

        if ($receipt->processed_at !== null) {
            return ['receipt' => $receipt, 'payment' => $payment, 'replayed' => true];
        }

        if ($source === self::SOURCE_WEBHOOK) {
            ProcessPaystationReceipt::dispatch($receipt->id);
        } else {
            $this->process($receipt->id);
        }

        return ['receipt' => $receipt->refresh(), 'payment' => $payment->refresh(), 'replayed' => false];
    }

    /**
     * Steps 3–4 for a recorded receipt. Safe to run twice: a processed receipt is skipped.
     * `deferred` leaves the receipt unprocessed so the job's retry (or a repeated IPN)
     * tries again: PayStation unreachable or unreadable, or — for an IPN, which is only
     * ever sent for a success — the query not showing the success yet.
     */
    public function process(int $receiptId): string
    {
        $receipt = WebhookReceipt::query()->find($receiptId);
        if ($receipt === null || $receipt->processed_at !== null) {
            return 'skipped';
        }

        $invoice = self::field($receipt->payload, 'invoice_number');
        $payment = $invoice === null ? null : self::payment($invoice);
        if ($payment === null) {
            self::finish($receipt, 'unknown_payment');

            return 'unknown_payment';
        }

        $answer = $this->gateway->verify($payment);
        $unchanged = $answer->payload['unchanged'] ?? null;
        $deferOn = $receipt->source === self::SOURCE_WEBHOOK ? self::NOT_YET : ['gateway_unreachable', 'malformed_response'];
        if (is_string($unchanged) && in_array($unchanged, $deferOn, true)) {
            $receipt->forceFill(['result' => 'deferred', 'error' => $unchanged])->save();

            return 'deferred';
        }

        $result = $this->reconcile->handle($payment, $answer);
        self::finish($receipt, $result['outcome'], $result['reason'] ?? (is_string($unchanged) ? $unchanged : null));

        return $result['outcome'];
    }

    private static function finish(WebhookReceipt $receipt, string $result, ?string $error = null): void
    {
        $receipt->forceFill(['processed_at' => now(), 'result' => $result, 'error' => $error === null ? null : Str::limit($error, 497)])->save();
    }

    /** @param  array<array-key, mixed>  $fields */
    private static function field(array $fields, string $key): ?string
    {
        $value = $fields[$key] ?? null;
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), 128, '') : null;
    }

    /**
     * Scalar fields only, bounded, minus the payer's phone (rule 20: never a full phone).
     *
     * @param  array<array-key, mixed>  $fields
     * @return array<string, string>
     */
    private static function storable(array $fields): array
    {
        $kept = [];
        foreach (array_slice($fields, 0, 40, true) as $key => $value) {
            if (is_scalar($value) && ! in_array((string) $key, ['payer_mobile_no', 'cust_phone', 'password', 'merchantId'], true)) {
                $kept[Str::limit((string) $key, 64, '')] = Str::limit((string) $value, 500, '');
            }
        }

        return $kept;
    }
}
