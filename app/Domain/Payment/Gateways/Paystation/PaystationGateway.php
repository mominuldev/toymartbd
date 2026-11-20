<?php

declare(strict_types=1);

namespace App\Domain\Payment\Gateways\Paystation;

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
use App\Support\Money;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * PayStation hosted checkout, built ONLY from the official documentation page
 * (paystation.com.bd/documentation — see docs/_research/paystation-api.md for exactly what
 * was and was not readable, and the "Not documented" list this driver's safe choices
 * answer). No merchant account exists yet: every test runs on hand-authored fixtures and
 * the provider row stays `verified = false`.
 *
 *   initiate → POST {host}/initiate-payment   → status_code "200" + payment_url
 *   verify   → POST {host}/transaction-status (header merchantId, body invoice_number)
 *
 * Our `invoice_number` is `{order number}-P{payment id}` — unique per attempt (PayStation
 * refuses a duplicate with 1008). It is stored in `payments.transaction_id`; PayStation's
 * `trx_id` becomes `gateway_reference` once the payment is paid.
 *
 * Paid means: status_code "200" **and** trx_status success **and** invoice_number equals
 * ours **and** the amount is exact. Anything else is never written as paid: an unknown
 * status leaves the payment unchanged, an amount mismatch is refused by
 * PaymentTransitions and flagged for review (rule 19).
 *
 * The initiate request carries the password in its body, so neither request bodies nor
 * transport exception messages are ever logged or copied into a result — only the
 * exception class (rule 20).
 */
final readonly class PaystationGateway implements ChecksConfiguration, ChecksConnection, PaymentGateway
{
    public const string PROVIDER = 'paystation';

    /** Fields of a status answer kept on the payment (no payer phone, no customer data). */
    private const array KEPT = ['invoice_number', 'trx_status', 'trx_id', 'payment_amount', 'trx_amount', 'order_date_time', 'payment_method', 'reference'];

    public function __construct(private HttpFactory $http) {}

    public function method(): PaymentMethod
    {
        return PaymentMethod::Paystation;
    }

    public function isConfigured(): bool
    {
        return PaystationCredentials::resolve() !== null;
    }

    public function isSandbox(): bool
    {
        return PaystationCredentials::sandboxMode();
    }

    public static function invoiceNumber(Payment $payment, string $orderNumber): string
    {
        return $orderNumber.'-P'.$payment->id;
    }

    public function initiate(Payment $payment, PaymentContext $context): GatewayResult
    {
        $credentials = PaystationCredentials::resolve();
        if ($credentials === null) {
            return self::failed('not_configured', ['action' => 'initiate']);
        }
        $invoice = self::invoiceNumber($payment, $context->orderNumber);

        // `payment_amount` is documented as an integer: money is never rounded, so an
        // amount with paisa cannot be sent and no session is opened.
        $whole = self::wholeTaka($payment->amount);
        if ($whole === null) {
            return self::failed('amount_not_whole_taka', ['action' => 'initiate', 'invoice_number' => $invoice], $invoice);
        }

        try {
            $response = $this->client()->asForm()->post($credentials->host().'/initiate-payment', [
                'merchantId' => $credentials->merchantId,
                'password' => $credentials->password,
                'invoice_number' => $invoice,
                'currency' => $payment->currency,
                'payment_amount' => $whole,
                // The merchant bears the gateway charge, so the customer pays exactly the
                // order total and the status answer can be matched exactly.
                'pay_with_charge' => '0',
                'reference' => $context->orderNumber,
                'cust_name' => Str::limit($context->customerName, 100, ''),
                'cust_phone' => $context->customerPhone,
                'cust_email' => $context->customerEmail ?? (string) config('payments.paystation.fallback_email'),
                'cust_address' => Str::limit($context->city ?? 'Bangladesh', 100, ''),
                'callback_url' => $context->successUrl,
            ]);
        } catch (Throwable $e) {
            self::logTransport('initiate', $e);

            return self::failed('gateway_unreachable', ['action' => 'initiate', 'invoice_number' => $invoice, 'error' => $e::class], $invoice);
        }

        $body = self::json($response);
        if ($body === null) {
            return self::failed('malformed_response', ['action' => 'initiate', 'invoice_number' => $invoice, 'http_status' => $response->status()], $invoice);
        }

        $code = self::str($body['status_code'] ?? null);
        $url = self::str($body['payment_url'] ?? null);
        $kept = [
            'action' => 'initiate',
            'invoice_number' => $invoice,
            'status_code' => $code,
            'status' => self::str($body['status'] ?? null),
            'message' => self::str($body['message'] ?? null),
            'payment_url' => $url,
        ];

        if ($code === '200' && $url !== null && filter_var($url, FILTER_VALIDATE_URL) !== false && str_starts_with($url, 'https://')) {
            return new GatewayResult(
                status: PaymentStatus::Initiated,
                transactionId: $invoice,
                redirectUrl: $url,
                payload: $kept,
            );
        }

        return self::failed($code !== null && $code !== '200' ? 'gateway_refused' : 'malformed_response', $kept, $invoice);
    }

    /**
     * The transaction-status query by our invoice number — the authoritative answer
     * (rule 19) for the browser return, an IPN, the sweeper and an admin re-check. An
     * answer we cannot read, or a status the docs do not define, changes nothing.
     */
    public function verify(Payment $payment): GatewayResult
    {
        $credentials = PaystationCredentials::resolve();
        if ($credentials === null || $payment->transaction_id === null) {
            return self::unchanged($payment, 'not_queryable');
        }

        try {
            $response = $this->client()
                ->withHeaders(['merchantId' => $credentials->merchantId])
                ->asForm()
                ->post($credentials->host().'/transaction-status', ['invoice_number' => $payment->transaction_id]);
        } catch (Throwable $e) {
            self::logTransport('verify', $e);

            return self::unchanged($payment, 'gateway_unreachable');
        }

        $body = self::json($response);
        if ($body === null) {
            return self::unchanged($payment, 'malformed_response', ['action' => 'query', 'http_status' => $response->status()]);
        }

        $code = self::str($body['status_code'] ?? null);
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $kept = ['action' => 'query', 'status_code' => $code, 'message' => self::str($body['message'] ?? null)] + self::keep($data);

        if ($code !== '200' || $data === []) {
            // 2001 means "invalid or not resolved" — not proof the attempt failed.
            return self::unchanged($payment, 'not_found', $kept);
        }
        if (self::str($data['invoice_number'] ?? null) !== $payment->transaction_id) {
            return self::unchanged($payment, 'invoice_mismatch', $kept);
        }

        return match (strtolower(self::str($data['trx_status'] ?? null) ?? '')) {
            'success', 'successful' => $this->paid($payment, $data, $kept),
            'failed' => new GatewayResult(PaymentStatus::Failed, failureReason: 'gateway_failed', payload: ['driver' => self::PROVIDER] + $kept),
            'processing' => self::unchanged($payment, 'processing', $kept),
            default => self::unchanged($payment, 'unknown_status', $kept),
        };
    }

    /** No refund API is documented: refunds are made in PayStation's merchant panel. */
    public function refund(Payment $payment, Money $amount, string $reason): RefundResult
    {
        return new RefundResult(RefundStatus::Failed, payload: ['driver' => self::PROVIDER, 'error' => 'not_supported']);
    }

    /**
     * Proves the CURRENT mode's keys by opening a ৳1 payment link with a probe invoice and
     * discarding it — nobody is charged. The documented status query needs only the
     * merchant id and answers 2001 for both "invalid" and "not found", so it cannot test
     * the password (docs/_research/paystation-api.md, "Not documented" 9).
     *
     * @return array{ok: bool, code: 'ok'|'not_configured'|'credentials_rejected'|'unreachable'|'sandbox_only'}
     */
    public function checkConnection(): array
    {
        $credentials = PaystationCredentials::resolve();
        if ($credentials === null) {
            return ['ok' => false, 'code' => 'not_configured'];
        }
        // Owner decision 2026-11-19: the only documented probe opens a ৳1 payment link, which
        // would sit unpaid in the LIVE merchant panel — so the check runs in sandbox only.
        if (! $credentials->sandbox) {
            return ['ok' => false, 'code' => 'sandbox_only'];
        }

        try {
            $response = $this->client()->asForm()->post($credentials->host().'/initiate-payment', [
                'merchantId' => $credentials->merchantId,
                'password' => $credentials->password,
                'invoice_number' => 'CHK-'.now()->format('ymdHis').Str::upper(Str::random(6)),
                'currency' => 'BDT',
                'payment_amount' => '1',
                'pay_with_charge' => '0',
                'reference' => 'connection-check',
                'cust_name' => 'Connection check',
                'cust_phone' => '01700000000',
                'cust_email' => (string) config('payments.paystation.fallback_email'),
                'callback_url' => (string) config('app.url'),
            ]);
        } catch (Throwable $e) {
            self::logTransport('check_connection', $e);

            return ['ok' => false, 'code' => 'unreachable'];
        }

        if ($response->serverError()) {
            return ['ok' => false, 'code' => 'unreachable'];
        }
        if (in_array($response->status(), [401, 403], true)) {
            return ['ok' => false, 'code' => 'credentials_rejected'];
        }

        $body = self::decode($response);
        $code = $body === null ? null : self::str($body['status_code'] ?? null);
        if ($code === null) {
            return ['ok' => false, 'code' => 'unreachable'];
        }

        return $code === '200' && $response->successful()
            ? ['ok' => true, 'code' => 'ok']
            : ['ok' => false, 'code' => 'credentials_rejected'];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, mixed>  $kept
     */
    private function paid(Payment $payment, array $data, array $kept): GatewayResult
    {
        $amount = self::money($data['payment_amount'] ?? null);

        return new GatewayResult(
            status: PaymentStatus::Paid,
            amount: $amount,
            transactionId: $payment->transaction_id,
            gatewayReference: self::str($data['trx_id'] ?? null),
            failureReason: $amount === null ? 'amount_unreadable' : null,
            payload: ['driver' => self::PROVIDER] + $kept,
        );
    }

    private function client(): PendingRequest
    {
        return $this->http->timeout((int) config('payments.paystation.timeout_seconds', 15))
            ->connectTimeout(5)
            ->acceptJson();
    }

    private static function wholeTaka(Money $amount): ?string
    {
        $decimal = $amount->toDecimal();

        return preg_match('/^(\d+)(?:\.0+)?$/', $decimal, $m) === 1 && $m[1] !== '0' ? $m[1] : null;
    }

    /** @return array<string, mixed>|null */
    private static function json(Response $response): ?array
    {
        return $response->successful() ? self::decode($response) : null;
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

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, string|null>
     */
    private static function keep(array $data): array
    {
        $kept = [];
        foreach (self::KEPT as $field) {
            if (array_key_exists($field, $data)) {
                $kept[$field] = self::str($data[$field]);
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
    private static function failed(string $reason, array $payload, ?string $invoice = null): GatewayResult
    {
        return new GatewayResult(PaymentStatus::Failed, transactionId: $invoice, failureReason: $reason, payload: ['driver' => self::PROVIDER] + $payload);
    }

    /** @param  array<string, mixed>  $payload */
    private static function unchanged(Payment $payment, string $reason, array $payload = []): GatewayResult
    {
        return new GatewayResult($payment->status, payload: ['driver' => self::PROVIDER, 'unchanged' => $reason] + $payload);
    }

    /** The exception class only — a transport message may embed request details. */
    private static function logTransport(string $action, Throwable $e): void
    {
        Log::warning('payment.paystation.transport_error', ['action' => $action, 'exception' => $e::class]);
    }
}
