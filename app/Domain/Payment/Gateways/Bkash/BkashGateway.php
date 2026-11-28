<?php

declare(strict_types=1);

namespace App\Domain\Payment\Gateways\Bkash;

use App\Domain\Payment\Contracts\ChecksConfiguration;
use App\Domain\Payment\Contracts\ChecksConnection;
use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\DTOs\GatewayResult;
use App\Domain\Payment\DTOs\PaymentContext;
use App\Domain\Payment\DTOs\RefundResult;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\Refund;
use App\Support\Money;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * bKash Tokenized Checkout, WITHOUT agreement — the one-off e-commerce flow — built ONLY from
 * the published docs at developer.bka.sh (read 2026-11-28; docs/_research/bkash-api.md lists
 * exactly what is documented and what is not). No merchant account exists: every test runs on
 * hand-authored fixtures and the provider row stays `verified = false` (the rule 33a pattern).
 *
 *   token    → POST {base}/auth/grant-token | /auth/refresh-token   ({@see BkashTokenStore})
 *   initiate → POST {base}/payment/create   → paymentId + bkashURL (transactionStatus Initiated)
 *   execute  → POST {base}/payment/execute  → the CAPTURE, only after a success callback
 *   verify   → POST {base}/query/payment    → the authoritative status and amount (rule 19)
 *   refund   → POST {base}/refund/payment/transaction (+ /refund/payment/status on a timeout)
 *
 * `payments.transaction_id` = bKash's `paymentId` (what every later call is keyed on);
 * `gateway_reference` = its `trxId` once paid. Our `merchantInvoiceNumber` is
 * `{order number}-P{payment id}` and must come back on the query.
 *
 * Paid means: the QUERY (never Execute's answer, never the callback) says `Completed`, for our
 * paymentId and our invoice, in BDT, with a trxId and an amount equal to ours — an unequal
 * amount is refused by PaymentTransitions and flagged for review. A status the docs do not
 * define changes nothing. Execute's answer is used only to tell a definite refusal (bKash says
 * a paymentId cannot be executed twice "whatever the result") from a timeout.
 *
 * The documented callback `signature` has no published verification method, so the callback
 * is a trigger only ({@see \App\Domain\Payment\Actions\HandleBkashCallback}).
 *
 * Fees: the query documents a `serviceFee` field but no rate is published; it is kept in the
 * payload and NOT booked (like SSLCommerz's fee — IMPLEMENTATION-PLAN "gateway fee not booked").
 *
 * Request bodies carry the app secret and tokens, so neither request bodies nor transport
 * exception messages are logged or copied into a result — only the exception class (rule 20).
 * The payer's wallet number (`payerAccount`) is never kept.
 */
final readonly class BkashGateway implements ChecksConfiguration, ChecksConnection, PaymentGateway
{
    public const string PROVIDER = 'bkash';

    /** Fields of a query / execute answer kept on the payment (no payer wallet, no customer data). */
    private const array KEPT = [
        'paymentId', 'trxId', 'transactionStatus', 'amount', 'currency', 'intent', 'merchantInvoice', 'merchantInvoiceNumber',
        'verificationStatus', 'paymentCreateTime', 'paymentExecuteTime', 'payerType', 'serviceFee', 'creditedAmount', 'couponAmount', 'maxRefundableAmount',
    ];

    public function __construct(private HttpFactory $http, private BkashTokenStore $tokens) {}

    public function method(): PaymentMethod
    {
        return PaymentMethod::Bkash;
    }

    public function isConfigured(): bool
    {
        return BkashCredentials::resolve() !== null;
    }

    public function isSandbox(): bool
    {
        return BkashCredentials::sandboxMode();
    }

    public static function invoiceNumber(Payment $payment, string $orderNumber): string
    {
        return $orderNumber.'-P'.$payment->id;
    }

    /** True for a payment this driver opened online — never a POS counter bKash tender (same method). */
    public static function isOnlinePayment(Payment $payment): bool
    {
        return $payment->method === PaymentMethod::Bkash && ($payment->gateway_payload['driver'] ?? null) === self::PROVIDER;
    }

    public function initiate(Payment $payment, PaymentContext $context): GatewayResult
    {
        $credentials = BkashCredentials::resolve();
        if ($credentials === null) {
            return self::failed('not_configured', ['action' => 'create']);
        }
        if ($payment->currency !== 'BDT') {
            return self::failed('currency_not_supported', ['action' => 'create']);
        }
        $invoice = self::invoiceNumber($payment, $context->orderNumber);
        $callback = self::callbackUrl($context->successUrl);
        if ($callback === null) {
            return self::failed('no_callback_url', ['action' => 'create', 'merchant_invoice' => $invoice]);
        }

        $answer = $this->post($credentials, '/payment/create', [
            // Not the customer's phone: bKash would preset it as the wallet, and we keep the
            // payer's details out of the gateway where we can (rule 20).
            'payerReference' => self::safe($context->orderNumber),
            'callbackURL' => $callback,
            'amount' => $payment->amount->toDecimal(),
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => self::safe($invoice),
        ], 'create');
        if ($answer['body'] === null) {
            return self::failed($answer['error'] ?? 'malformed_response', ['action' => 'create', 'merchant_invoice' => $invoice] + $answer['meta']);
        }

        $body = $answer['body'];
        $paymentId = self::field($body, 'paymentId', 'paymentID');
        $url = self::field($body, 'bkashURL', 'bKashURL');
        $kept = ['action' => 'create', 'merchant_invoice' => $invoice, 'payment_id' => $paymentId, 'redirect_url' => $url]
            + array_filter(['transaction_status' => self::field($body, 'transactionStatus'), 'status_code' => self::field($body, 'statusCode')]);

        if (self::errorCode($body) !== null) {
            return self::failed('gateway_refused', $kept + ['error_code' => self::errorCode($body)]);
        }
        $echoed = self::money(self::field($body, 'amount'));
        if ($echoed !== null && ! $echoed->equals($payment->amount)) {
            return self::failed('amount_mismatch_on_create', $kept);
        }
        if ($paymentId === null || $url === null || filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with($url, 'https://')) {
            return self::failed('malformed_response', $kept);
        }

        return new GatewayResult(
            status: PaymentStatus::Initiated,
            transactionId: $paymentId,
            redirectUrl: $url,
            payload: ['driver' => self::PROVIDER] + $kept,
        );
    }

    /**
     * Execute Payment — the capture. Called ONLY after the customer's success callback, never
     * to decide money: the caller re-queries afterwards and applies the query's answer.
     *
     * `refused` = bKash answered with an error code (the paymentId is now spent); `unknown` =
     * timeout or unreadable answer (settle by query — bKash's documented fallback).
     *
     * @return array{outcome: 'sent'|'refused'|'unknown', code: string|null}
     */
    public function execute(Payment $payment): array
    {
        $credentials = BkashCredentials::resolve();
        if ($credentials === null || ! self::isOnlinePayment($payment) || $payment->transaction_id === null) {
            return ['outcome' => 'unknown', 'code' => 'not_executable'];
        }

        $answer = $this->post($credentials, '/payment/execute', ['paymentId' => $payment->transaction_id], 'execute');
        if ($answer['body'] === null) {
            return ['outcome' => 'unknown', 'code' => $answer['error'] ?? 'malformed_response'];
        }
        $code = self::errorCode($answer['body']);

        return $code === null ? ['outcome' => 'sent', 'code' => null] : ['outcome' => 'refused', 'code' => $code];
    }

    /**
     * Query Payment by our paymentId — the authoritative answer (rule 19) for the callback,
     * the expiry sweeper and an admin re-check. An answer we cannot read, or a status the docs
     * do not define, changes nothing.
     */
    public function verify(Payment $payment): GatewayResult
    {
        $credentials = BkashCredentials::resolve();
        if ($credentials === null || ! self::isOnlinePayment($payment) || $payment->transaction_id === null) {
            return self::unchanged($payment, 'not_queryable');
        }

        $answer = $this->post($credentials, '/query/payment', ['paymentId' => $payment->transaction_id], 'query');
        if ($answer['body'] === null) {
            return self::unchanged($payment, $answer['error'] ?? 'malformed_response', ['action' => 'query'] + $answer['meta']);
        }

        $body = $answer['body'];
        $kept = ['action' => 'query'] + self::keep($body);
        if (($code = self::errorCode($body)) !== null) {
            // 2002 "Invalid Payment ID" — not proof the attempt failed.
            return self::unchanged($payment, $code === '2002' ? 'not_found' : 'gateway_error', $kept + ['error_code' => $code]);
        }
        if (self::field($body, 'paymentId', 'paymentID') !== $payment->transaction_id) {
            return self::unchanged($payment, 'payment_id_mismatch', $kept);
        }
        $ours = $payment->gateway_payload['merchant_invoice'] ?? null;
        $theirs = self::field($body, 'merchantInvoice', 'merchantInvoiceNumber');
        if ($theirs !== null && is_string($ours) && $theirs !== $ours) {
            return self::unchanged($payment, 'invoice_mismatch', $kept);
        }

        return match (self::field($body, 'transactionStatus')) {
            'Completed' => $this->paid($payment, $body, $kept),
            // Documented: created, or "cancelled or failed verification" — still open on bKash's side.
            'Initiated' => self::unchanged($payment, 'initiated', $kept),
            default => self::unchanged($payment, 'unknown_status', $kept),
        };
    }

    /**
     * Refund (v2) of a paid online bKash payment: up to 10 partial refunds, within 60 days, at
     * most `maxRefundableAmount`. On a timeout bKash says to ask Refund Status before retrying
     * (and never to repeat a refund within 10 minutes): a Completed refund of exactly this
     * amount that none of our refunds already records is taken as this one; otherwise failed,
     * so staff retry later rather than paying twice.
     */
    public function refund(Payment $payment, Money $amount, string $reason): RefundResult
    {
        $credentials = BkashCredentials::resolve();
        $trxId = $payment->gateway_reference;
        if ($credentials === null || ! self::isOnlinePayment($payment) || $payment->transaction_id === null || $trxId === null) {
            return new RefundResult(RefundStatus::Failed, payload: ['driver' => self::PROVIDER, 'error' => 'not_refundable_online']);
        }

        $answer = $this->post($credentials, '/refund/payment/transaction', [
            'paymentId' => $payment->transaction_id,
            'trxId' => $trxId,
            'refundAmount' => $amount->toDecimal(),
            'sku' => self::safe((string) ($payment->gateway_payload['merchant_invoice'] ?? 'order')),
            'reason' => self::safe(Str::limit($reason !== '' ? $reason : 'refund', 255, '')),
        ], 'refund');

        if ($answer['body'] === null) {
            return $this->refundByStatus($credentials, $payment, $trxId, $amount, $answer['error'] ?? 'malformed_response');
        }
        $body = $answer['body'];
        if (($code = self::errorCode($body)) !== null) {
            return new RefundResult(RefundStatus::Failed, payload: ['driver' => self::PROVIDER, 'error' => 'gateway_refused', 'error_code' => $code]);
        }
        $refundTrx = self::field($body, 'refundTrxId', 'refundTrxID');
        if (self::field($body, 'refundTransactionStatus') === 'Completed' && $refundTrx !== null) {
            return new RefundResult(RefundStatus::Completed, $refundTrx, ['driver' => self::PROVIDER, 'refund_trx_id' => $refundTrx, 'refund_amount' => self::field($body, 'refundAmount')]);
        }

        return $this->refundByStatus($credentials, $payment, $trxId, $amount, 'unknown_status');
    }

    /**
     * Proves the CURRENT mode's keys with a Grant Token call — it takes no money and opens
     * nothing, so unlike PayStation it is safe in live mode too.
     *
     * @return array{ok: bool, code: 'ok'|'not_configured'|'credentials_rejected'|'unreachable'|'sandbox_only'}
     */
    public function checkConnection(): array
    {
        $credentials = BkashCredentials::resolve();
        if ($credentials === null) {
            return ['ok' => false, 'code' => 'not_configured'];
        }

        $code = $this->tokens->check($credentials);

        return ['ok' => $code === 'ok', 'code' => $code];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $kept
     */
    private function paid(Payment $payment, array $body, array $kept): GatewayResult
    {
        $trxId = self::field($body, 'trxId', 'trxID');
        $currency = self::field($body, 'currency');
        if ($trxId === null) {
            return self::unchanged($payment, 'no_trx_id', $kept);
        }
        if ($currency !== null && $currency !== $payment->currency) {
            return new GatewayResult(PaymentStatus::Paid, amount: null, transactionId: $payment->transaction_id, gatewayReference: $trxId, failureReason: 'currency_mismatch', payload: ['driver' => self::PROVIDER] + $kept);
        }
        $amount = self::money(self::field($body, 'amount'));

        return new GatewayResult(
            status: PaymentStatus::Paid,
            amount: $amount,
            transactionId: $payment->transaction_id,
            gatewayReference: $trxId,
            failureReason: $amount === null ? 'amount_unreadable' : null,
            payload: ['driver' => self::PROVIDER, 'merchant_invoice' => $payment->gateway_payload['merchant_invoice'] ?? null] + $kept,
        );
    }

    private function refundByStatus(BkashCredentials $credentials, Payment $payment, string $trxId, Money $amount, string $why): RefundResult
    {
        $answer = $this->post($credentials, '/refund/payment/status', ['paymentId' => (string) $payment->transaction_id, 'trxId' => $trxId], 'refund_status');
        $list = $answer['body']['refundTransactions'] ?? null;
        if (is_array($list)) {
            $known = Refund::query()->where('payment_id', $payment->id)->whereNotNull('gateway_reference')->pluck('gateway_reference')->all();
            foreach ($list as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $refundTrx = self::field($row, 'refundTrxId', 'refundTrxID');
                $refunded = self::money(self::field($row, 'refundAmount'));
                if ($refundTrx !== null && ! in_array($refundTrx, $known, true) && self::field($row, 'refundTransactionStatus') === 'Completed'
                    && $refunded !== null && $refunded->equals($amount)) {
                    return new RefundResult(RefundStatus::Completed, $refundTrx, ['driver' => self::PROVIDER, 'refund_trx_id' => $refundTrx, 'via' => 'refund_status']);
                }
            }
        }

        return new RefundResult(RefundStatus::Failed, payload: ['driver' => self::PROVIDER, 'error' => $why, 'refund_status_checked' => is_array($list)]);
    }

    /**
     * One authorised call. A 401 drops the cached token and retries once with a fresh one.
     *
     * @param  array<string, string>  $body
     * @return array{body: array<string, mixed>|null, error: string|null, meta: array<string, mixed>}
     */
    private function post(BkashCredentials $credentials, string $path, array $body, string $action): array
    {
        $response = $this->send($credentials, $path, $body, $action, renew: false);
        if ($response instanceof Response && $response->status() === 401) {
            $this->tokens->forget($credentials);
            $response = $this->send($credentials, $path, $body, $action, renew: true);
        }
        if (is_string($response)) {
            return ['body' => null, 'error' => $response, 'meta' => []];
        }
        if ($response->status() === 401) {
            return ['body' => null, 'error' => 'credentials_rejected', 'meta' => ['http_status' => 401]];
        }
        if ($response->serverError()) {
            return ['body' => null, 'error' => 'gateway_unreachable', 'meta' => ['http_status' => $response->status()]];
        }

        $decoded = self::decode($response);

        return $decoded === null
            ? ['body' => null, 'error' => 'malformed_response', 'meta' => ['http_status' => $response->status()]]
            : ['body' => $decoded, 'error' => null, 'meta' => []];
    }

    /**
     * @param  array<string, string>  $body
     * @return Response|'token_unavailable'|'gateway_unreachable'
     */
    private function send(BkashCredentials $credentials, string $path, array $body, string $action, bool $renew): Response|string
    {
        $token = $this->tokens->token($credentials, $renew);
        if ($token === null) {
            return 'token_unavailable';
        }
        $authorization = config('payments.bkash.authorization') === 'bearer' ? 'Bearer '.$token : $token;

        try {
            return $this->http->timeout((int) config('payments.bkash.timeout_seconds', 30))
                ->connectTimeout(5)
                ->acceptJson()
                ->asJson()
                ->withHeaders(['Authorization' => $authorization, 'X-App-Key' => $credentials->appKey])
                ->post($credentials->baseUrl.$path, $body);
        } catch (Throwable $e) {
            Log::warning('payment.bkash.transport_error', ['action' => $action, 'exception' => $e::class]);

            return 'gateway_unreachable';
        }
    }

    /**
     * bKash's base callbackURL. bKash appends `?paymentID=…&status=…&signature=…`; the docs do
     * not say what happens when callbackURL already has a query string, so ours never has one
     * (the storefront's handler reads bKash's `status`, not our path's outcome segment).
     */
    private static function callbackUrl(?string $successUrl): ?string
    {
        if ($successUrl === null || (! str_starts_with($successUrl, 'https://') && ! str_starts_with($successUrl, 'http://'))) {
            return null;
        }

        return strtok($successUrl, '?#') ?: null;
    }

    /** bKash rejects `<`, `>` and `&` in its free-text fields (`^[^<>&]*$`). */
    private static function safe(string $value): string
    {
        return Str::limit(str_replace(['<', '>', '&'], ' ', $value), 255, '');
    }

    /**
     * bKash's error code: `externalCode` (v2 samples), `errorCode` (older samples) or a
     * `statusCode` other than "0000". Null when the body is not an error.
     *
     * @param  array<array-key, mixed>  $body
     */
    private static function errorCode(array $body): ?string
    {
        $code = self::field($body, 'externalCode') ?? self::field($body, 'errorCode');
        if ($code !== null) {
            return $code;
        }
        $status = self::field($body, 'statusCode');

        return $status !== null && $status !== '0000' ? $status : null;
    }

    /**
     * The first of these keys present (bKash's samples mix `paymentId` and `paymentID`).
     *
     * @param  array<array-key, mixed>  $data
     */
    private static function field(array $data, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            if (is_string($value) && trim($value) !== '') {
                return Str::limit(trim($value), 191, '');
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, string|null>
     */
    private static function keep(array $data): array
    {
        $kept = [];
        foreach (self::KEPT as $key) {
            if (array_key_exists($key, $data)) {
                $kept[Str::snake($key)] = self::field($data, $key);
            }
        }

        return $kept;
    }

    private static function money(?string $value): ?Money
    {
        if ($value === null || preg_match('/^\d{1,12}(\.\d{1,2})?$/', $value) !== 1) {
            return null;
        }

        return Money::of($value);
    }

    /** @return array<string, mixed>|null */
    private static function decode(Response $response): ?array
    {
        try {
            $decoded = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    /** @param  array<string, mixed>  $payload */
    private static function failed(string $reason, array $payload): GatewayResult
    {
        return new GatewayResult(PaymentStatus::Failed, failureReason: $reason, payload: ['driver' => self::PROVIDER] + $payload);
    }

    /** @param  array<string, mixed>  $payload */
    private static function unchanged(Payment $payment, string $reason, array $payload = []): GatewayResult
    {
        return new GatewayResult($payment->status, payload: ['driver' => self::PROVIDER, 'unchanged' => $reason] + $payload);
    }
}
