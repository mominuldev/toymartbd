<?php

declare(strict_types=1);

namespace App\Domain\Payment\Gateways\Sslcommerz;

use App\Domain\Payment\Contracts\ChecksConfiguration;
use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\DTOs\GatewayResult;
use App\Domain\Payment\DTOs\PaymentContext;
use App\Domain\Payment\DTOs\RefundResult;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Models\Payment;
use App\Support\Money;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * SSLCommerz v4 hosted checkout (P4-PAY-02), built ONLY from the published v4 API
 * (developer.sslcommerz.com/doc/v4) and the official SSLCommerz-PHP library's behaviour —
 * no merchant account exists yet (Gate 1 decision 5), so every test runs on hand-authored
 * fixtures and the provider row stays `verified = false`.
 *
 *   initiate → POST /gwprocess/v4/api.php → `status: SUCCESS` + `GatewayPageURL` + `sessionkey`
 *   validate → GET /validator/api/validationserverAPI.php?val_id=…   (the authoritative answer)
 *   verify   → GET /validator/api/merchantTransIDvalidationAPI.php?tran_id=…  (query by our tran_id)
 *   refund   → GET /validator/api/merchantTransIDvalidationAPI.php?bank_tran_id=…&refund_amount=…
 *
 * Our `tran_id` is `{order number}-P{payment id}` — unique per attempt, so a retry is a new
 * session and an old session can never be confused with it. It is stored in
 * `payments.transaction_id`; the session key goes to `gateway_reference` until the payment
 * is paid, when `gateway_reference` becomes SSLCommerz's `bank_tran_id` (what refunds need).
 *
 * Paid means: status VALID/VALIDATED **and** tran_id equals ours **and** currency equals
 * the payment's **and** the amount is exact (stricter than the library's `< 1` tolerance).
 * A currency mismatch returns `paid` with no amount, so PaymentTransitions refuses it and
 * the caller flags the payment for review rather than marking it paid (rule 19).
 *
 * Never logs or stores a credential (rule 20): requests carry `store_passwd`, so neither
 * request URLs nor transport exception messages (which embed the URL) are ever logged or
 * copied into a result — only the exception class.
 */
final readonly class SslcommerzGateway implements ChecksConfiguration, PaymentGateway
{
    public const string PROVIDER = 'sslcommerz';

    private const array PAID = ['VALID', 'VALIDATED'];

    /** Fields of a validation / query answer kept on the payment (no card number, no customer data). */
    private const array KEPT = [
        'status', 'tran_id', 'val_id', 'amount', 'store_amount', 'currency', 'bank_tran_id', 'card_type',
        'card_brand', 'currency_type', 'currency_amount', 'risk_level', 'risk_title', 'tran_date', 'validated_on', 'APIConnect', 'error',
    ];

    public function __construct(private HttpFactory $http) {}

    public function method(): PaymentMethod
    {
        return PaymentMethod::Sslcommerz;
    }

    public function isConfigured(): bool
    {
        return SslcommerzCredentials::resolve() !== null;
    }

    public function isSandbox(): bool
    {
        return SslcommerzCredentials::sandboxMode();
    }

    public static function tranId(Payment $payment, string $orderNumber): string
    {
        return $orderNumber.'-P'.$payment->id;
    }

    public function initiate(Payment $payment, PaymentContext $context): GatewayResult
    {
        $credentials = SslcommerzCredentials::resolve();
        if ($credentials === null) {
            return self::failed('not_configured', ['action' => 'initiate']);
        }
        $tranId = self::tranId($payment, $context->orderNumber);

        try {
            $response = $this->client()->asForm()->post($credentials->host().'/gwprocess/v4/api.php', [
                'store_id' => $credentials->storeId,
                'store_passwd' => $credentials->storePassword,
                'total_amount' => $payment->amount->toDecimal(),
                'currency' => $payment->currency,
                'tran_id' => $tranId,
                'success_url' => $context->successUrl,
                'fail_url' => $context->failureUrl,
                'cancel_url' => $context->cancelUrl ?? $context->failureUrl,
                'ipn_url' => $context->notifyUrl,
                'cus_name' => Str::limit($context->customerName, 50, ''),
                'cus_email' => $context->customerEmail ?? (string) config('payments.sslcommerz.fallback_email'),
                'cus_add1' => Str::limit($context->city ?? 'Bangladesh', 50, ''),
                'cus_city' => Str::limit($context->city ?? 'Bangladesh', 50, ''),
                'cus_postcode' => $context->postcode ?? '0000',
                'cus_country' => 'Bangladesh',
                'cus_phone' => $context->customerPhone,
                // Physical goods, but the courier ships them: no shipping address goes to the
                // gateway (data minimisation). Whether SSLCommerz requires it for this store
                // profile is an onboarding question — [UNVERIFIED].
                'shipping_method' => 'NO',
                'num_of_item' => 1,
                'product_name' => 'Order '.$context->orderNumber,
                'product_category' => 'Toys',
                'product_profile' => 'physical-goods',
                'value_a' => $context->orderNumber,
            ]);
        } catch (Throwable $e) {
            return self::transportFailure('initiate', $e, $tranId);
        }

        $body = self::json($response);
        if ($body === null) {
            return self::failed('malformed_response', ['action' => 'initiate', 'tran_id' => $tranId, 'http_status' => $response->status()], $tranId);
        }

        $status = strtoupper(self::str($body['status'] ?? null) ?? '');
        $url = self::str($body['GatewayPageURL'] ?? null);
        $session = self::str($body['sessionkey'] ?? null);
        $kept = ['action' => 'initiate', 'tran_id' => $tranId, 'status' => $status, 'sessionkey' => $session, 'GatewayPageURL' => $url, 'failedreason' => self::str($body['failedreason'] ?? null)];

        if ($status === 'SUCCESS' && $url !== null && filter_var($url, FILTER_VALIDATE_URL) !== false && str_starts_with($url, 'https://')) {
            return new GatewayResult(
                status: PaymentStatus::Initiated,
                transactionId: $tranId,
                gatewayReference: $session,
                redirectUrl: $url,
                payload: $kept,
            );
        }
        if ($status === 'FAILED') {
            return self::failed('gateway_refused', $kept, $tranId);
        }

        return self::failed('malformed_response', $kept, $tranId);
    }

    /**
     * Query by our tran_id (rule 19's re-query when no val_id is at hand: the sweeper, an
     * admin re-check, a FAILED/CANCELLED notification). An answer we cannot read changes
     * nothing: the current status comes back.
     */
    public function verify(Payment $payment): GatewayResult
    {
        $credentials = SslcommerzCredentials::resolve();
        if ($credentials === null || $payment->transaction_id === null) {
            return self::unchanged($payment, 'not_queryable');
        }

        try {
            $response = $this->client()->get($credentials->host().'/validator/api/merchantTransIDvalidationAPI.php', [
                'tran_id' => $payment->transaction_id,
                'store_id' => $credentials->storeId,
                'store_passwd' => $credentials->storePassword,
                'format' => 'json',
            ]);
        } catch (Throwable $e) {
            self::logTransport('verify', $e);

            return self::unchanged($payment, 'gateway_unreachable');
        }

        $body = self::json($response);
        if ($body === null || strtoupper(self::str($body['APIConnect'] ?? null) ?? '') !== 'DONE') {
            return self::unchanged($payment, 'malformed_response', ['action' => 'query', 'APIConnect' => is_array($body) ? self::str($body['APIConnect'] ?? null) : null]);
        }

        $elements = is_array($body['element'] ?? null) ? array_values(array_filter($body['element'], is_array(...))) : [];
        if ($elements === []) {
            return self::unchanged($payment, 'not_found', ['action' => 'query', 'no_of_trans_found' => 0]);
        }

        // A paid attempt wins over a failed one for the same tran_id.
        foreach ($elements as $element) {
            if (in_array(strtoupper(self::str($element['status'] ?? null) ?? ''), self::PAID, true)) {
                return $this->fromValidation($payment, $element, 'query');
            }
        }
        $statuses = array_map(static fn (array $e): string => strtoupper(self::str($e['status'] ?? null) ?? ''), $elements);
        $kept = ['action' => 'query', 'elements' => array_map(self::keep(...), $elements)];

        if (in_array('PENDING', $statuses, true)) {
            return new GatewayResult($payment->status->isOpen() ? $payment->status : PaymentStatus::Initiated, payload: $kept);
        }
        if (array_intersect($statuses, ['FAILED', 'CANCELLED', 'UNATTEMPTED', 'EXPIRED']) !== []) {
            $first = $statuses[0];

            return new GatewayResult(self::closedStatus($first), failureReason: 'gateway_'.strtolower($first), payload: $kept);
        }

        return self::unchanged($payment, 'unknown_status', $kept);
    }

    /** The Order Validation API for a `val_id` from a signed notification (rule 19). */
    public function validate(Payment $payment, string $valId): GatewayResult
    {
        $credentials = SslcommerzCredentials::resolve();
        if ($credentials === null) {
            return self::unchanged($payment, 'not_configured');
        }

        try {
            $response = $this->client()->get($credentials->host().'/validator/api/validationserverAPI.php', [
                'val_id' => $valId,
                'store_id' => $credentials->storeId,
                'store_passwd' => $credentials->storePassword,
                'v' => 1,
                'format' => 'json',
            ]);
        } catch (Throwable $e) {
            self::logTransport('validate', $e);

            return self::unchanged($payment, 'gateway_unreachable');
        }

        $body = self::json($response);
        if ($body === null || ! isset($body['status'])) {
            return self::unchanged($payment, 'malformed_response', ['action' => 'validate', 'http_status' => $response->status()]);
        }
        if (! in_array(strtoupper(self::str($body['status']) ?? ''), self::PAID, true)) {
            // INVALID_TRANSACTION: the val_id is not a valid payment. That is not proof the
            // attempt failed (a forged or stale val_id must not close a real session).
            return self::unchanged($payment, 'validation_'.strtolower(self::str($body['status']) ?? 'unknown'), ['action' => 'validate'] + self::keep($body));
        }

        return $this->fromValidation($payment, $body, 'validate');
    }

    public function refund(Payment $payment, Money $amount, string $reason): RefundResult
    {
        $credentials = SslcommerzCredentials::resolve();
        $bankTranId = $payment->gateway_reference;
        if ($credentials === null || $payment->status !== PaymentStatus::Paid || $bankTranId === null) {
            return new RefundResult(RefundStatus::Failed, payload: ['driver' => self::PROVIDER, 'error' => 'not_refundable']);
        }
        // Unique per request, ≤ 30 chars (refund_trans_id, added to the v4 API 2025-02-24).
        $refundTransId = Str::limit('RF'.$payment->id.'T'.now()->format('ymdHis').Str::upper(Str::random(4)), 30, '');

        try {
            $response = $this->client()->get($credentials->host().'/validator/api/merchantTransIDvalidationAPI.php', [
                'bank_tran_id' => $bankTranId,
                'refund_trans_id' => $refundTransId,
                'refund_amount' => $amount->toDecimal(),
                'refund_remarks' => Str::limit($reason !== '' ? $reason : 'Refund', 250, ''),
                'refe_id' => $payment->transaction_id,
                'store_id' => $credentials->storeId,
                'store_passwd' => $credentials->storePassword,
                'format' => 'json',
            ]);
        } catch (Throwable $e) {
            self::logTransport('refund', $e);

            return new RefundResult(RefundStatus::Failed, payload: ['driver' => self::PROVIDER, 'error' => 'gateway_unreachable']);
        }

        $body = self::json($response);
        $kept = ['driver' => self::PROVIDER, 'refund_trans_id' => $refundTransId];
        if ($body === null) {
            return new RefundResult(RefundStatus::Failed, payload: $kept + ['error' => 'malformed_response']);
        }
        foreach (['APIConnect', 'bank_tran_id', 'trans_id', 'refund_ref_id', 'status', 'errorReason'] as $field) {
            $kept[$field] = self::str($body[$field] ?? null);
        }
        if (strtoupper($kept['APIConnect'] ?? '') !== 'DONE') {
            return new RefundResult(RefundStatus::Failed, payload: $kept);
        }

        // `success` means the refund request was accepted, not that the money has reached
        // the customer (SSLCommerz FAQ: 5–7 working days) — so it stays `processing` until
        // the refund query reports `refunded` ({@see refundStatus()}).
        return match (strtolower($kept['status'] ?? '')) {
            'success', 'processing' => new RefundResult(RefundStatus::Processing, $kept['refund_ref_id'], $kept),
            default => new RefundResult(RefundStatus::Failed, $kept['refund_ref_id'], $kept),
        };
    }

    /**
     * Refund query API by `refund_ref_id`: `refunded` → completed, `processing` → processing.
     * The v4 doc describes `cancelled` as "proceeded successfully", contradicting its name
     * — treated as failed until SSLCommerz confirms ([UNVERIFIED]).
     */
    public function refundStatus(string $refundRefId): RefundResult
    {
        $credentials = SslcommerzCredentials::resolve();
        if ($credentials === null) {
            return new RefundResult(RefundStatus::Processing, $refundRefId, ['driver' => self::PROVIDER, 'error' => 'not_configured']);
        }

        try {
            $response = $this->client()->get($credentials->host().'/validator/api/merchantTransIDvalidationAPI.php', [
                'refund_ref_id' => $refundRefId,
                'store_id' => $credentials->storeId,
                'store_passwd' => $credentials->storePassword,
                'format' => 'json',
            ]);
        } catch (Throwable $e) {
            self::logTransport('refund_query', $e);

            return new RefundResult(RefundStatus::Processing, $refundRefId, ['driver' => self::PROVIDER, 'error' => 'gateway_unreachable']);
        }

        $body = self::json($response) ?? [];
        $kept = ['driver' => self::PROVIDER];
        foreach (['APIConnect', 'bank_tran_id', 'tran_id', 'refund_ref_id', 'initiated_on', 'refunded_on', 'status', 'errorReason'] as $field) {
            $kept[$field] = self::str($body[$field] ?? null);
        }
        if (strtoupper($kept['APIConnect'] ?? '') !== 'DONE') {
            return new RefundResult(RefundStatus::Processing, $refundRefId, $kept);
        }

        return match (strtolower($kept['status'] ?? '')) {
            'refunded' => new RefundResult(RefundStatus::Completed, $refundRefId, $kept),
            'processing' => new RefundResult(RefundStatus::Processing, $refundRefId, $kept),
            default => new RefundResult(RefundStatus::Failed, $refundRefId, $kept),
        };
    }

    /** @param  array<string, mixed>  $answer  a VALID/VALIDATED validation answer or query element */
    private function fromValidation(Payment $payment, array $answer, string $action): GatewayResult
    {
        $kept = ['action' => $action] + self::keep($answer);

        if (self::str($answer['tran_id'] ?? null) !== $payment->transaction_id) {
            return self::unchanged($payment, 'tran_id_mismatch', $kept);
        }

        $amount = self::money($answer['amount'] ?? null);
        $currency = strtoupper(self::str($answer['currency'] ?? null) ?? '');
        if ($currency !== strtoupper($payment->currency)) {
            // Paid in a currency we did not ask for: `paid` with no amount is refused by
            // PaymentTransitions, and the caller flags it for review.
            return new GatewayResult(PaymentStatus::Paid, amount: null, failureReason: 'currency_mismatch', payload: $kept);
        }

        $storeAmount = self::money($answer['store_amount'] ?? null);
        $fee = $amount !== null && $storeAmount !== null && ! $storeAmount->greaterThan($amount)
            ? $amount->minus($storeAmount)
            : null;

        return new GatewayResult(
            status: PaymentStatus::Paid,
            amount: $amount,
            transactionId: $payment->transaction_id,
            gatewayReference: self::str($answer['bank_tran_id'] ?? null),
            failureReason: $amount === null ? 'amount_unreadable' : null,
            fee: $fee,
            payload: $kept,
        );
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return $this->http->timeout((int) config('payments.sslcommerz.timeout_seconds', 15))
            ->connectTimeout(5)
            ->acceptJson();
    }

    public static function closedStatus(string $providerStatus): PaymentStatus
    {
        return match (strtoupper($providerStatus)) {
            'CANCELLED' => PaymentStatus::Cancelled,
            'EXPIRED', 'UNATTEMPTED' => PaymentStatus::Expired,
            default => PaymentStatus::Failed,
        };
    }

    /** @return array<string, mixed>|null */
    private static function json(Response $response): ?array
    {
        if (! $response->successful()) {
            return null;
        }
        try {
            $decoded = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $answer
     * @return array<string, string|null>
     */
    private static function keep(array $answer): array
    {
        $kept = [];
        foreach (self::KEPT as $field) {
            if (array_key_exists($field, $answer)) {
                $kept[$field] = self::str($answer[$field]);
            }
        }

        return $kept;
    }

    private static function str(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), 191, '') : null;
    }

    private static function money(mixed $value): ?Money
    {
        $value = self::str($value);
        if ($value === null || preg_match('/^\d{1,12}(\.\d{1,2})?$/', $value) !== 1) {
            return null;
        }

        return Money::of($value);
    }

    /** @param  array<string, mixed>  $payload */
    private static function failed(string $reason, array $payload, ?string $tranId = null): GatewayResult
    {
        return new GatewayResult(PaymentStatus::Failed, transactionId: $tranId, failureReason: $reason, payload: ['driver' => self::PROVIDER] + $payload);
    }

    /** @param  array<string, mixed>  $payload */
    private static function unchanged(Payment $payment, string $reason, array $payload = []): GatewayResult
    {
        return new GatewayResult($payment->status, failureReason: null, payload: ['driver' => self::PROVIDER, 'unchanged' => $reason] + $payload);
    }

    private static function transportFailure(string $action, Throwable $e, string $tranId): GatewayResult
    {
        self::logTransport($action, $e);

        return self::failed('gateway_unreachable', ['action' => $action, 'tran_id' => $tranId, 'error' => $e::class], $tranId);
    }

    /** The exception class only — its message embeds the request URL, which carries store_passwd. */
    private static function logTransport(string $action, Throwable $e): void
    {
        Log::warning('payment.sslcommerz.transport_error', ['action' => $action, 'exception' => $e::class]);
    }
}
