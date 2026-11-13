<?php

declare(strict_types=1);

namespace Tests\Feature\Payment\Sslcommerz;

use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Gateways\Sslcommerz\SslcommerzSignature;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentProvider;
use App\Support\Money;
use Illuminate\Support\Facades\Http;

/**
 * SSLCommerz test fixtures — HAND-AUTHORED, NOT RECORDED.
 *
 * No SSLCommerz merchant or sandbox account exists yet (Gate 1 decision 5). Every file in
 * tests/Fixtures/Sslcommerz is hand-authored from the field names and sample shapes in the
 * published v4 API reference (developer.sslcommerz.com/doc/v4, read 2026-11-12) and the
 * official PHP library (sslcommerz/SSLCommerz-PHP, lib/SslCommerzNotification.php). Values
 * are illustrative. `{{placeholders}}` are filled per test. Replace them with recorded
 * sandbox responses once a store id exists.
 *
 * The network is never called: every test fakes the SSLCommerz hosts and prevents stray
 * requests.
 */
final class Fixtures
{
    public const string STORE_ID = 'toyma0000000000test';

    public const string STORE_PASSWORD = 'toyma0000000000test@ssl';

    public const string SANDBOX = 'https://sandbox.sslcommerz.com';

    /** Sandbox credentials in config, and the provider row active (unverified, as in production today). */
    public static function configure(bool $active = true): void
    {
        config([
            'payments.sslcommerz.store_id' => self::STORE_ID,
            'payments.sslcommerz.store_password' => self::STORE_PASSWORD,
            'payments.sslcommerz.sandbox' => true,
            'payments.sslcommerz.return_base_url' => 'https://shop.example.test',
            'payments.sslcommerz.ipn_allowed_ips' => [],
        ]);
        PaymentProvider::query()->where('code', 'sslcommerz')->update(['is_active' => $active]);
        Http::preventStrayRequests();
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    public static function load(string $name, array $values = []): array
    {
        $raw = (string) file_get_contents(base_path("tests/Fixtures/Sslcommerz/{$name}.json"));
        foreach ($values as $key => $value) {
            $raw = str_replace('{{'.$key.'}}', $value, $raw);
        }

        /** @var array<string, mixed> */
        return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> the placeholders for a payment */
    public static function values(Payment $payment, ?string $amount = null, string $fee = '2.50'): array
    {
        $amount ??= $payment->amount->toDecimal();
        $percent = Money::of($amount)->percentage($fee);

        return [
            'tran_id' => (string) $payment->transaction_id,
            'val_id' => '2611121600451XyZaBcDeFgH',
            'amount' => $amount,
            'store_amount' => Money::of($amount)->minus($percent)->toDecimal(),
            'store_id' => self::STORE_ID,
        ];
    }

    /**
     * A notification POST, signed the way SSLCommerz's library verifies it.
     *
     * @param  array<string, string>  $values
     * @param  array<string, string>  $tamper  fields changed AFTER signing
     * @return array<string, string>
     */
    public static function signedNotification(string $name, array $values, array $tamper = [], ?string $password = null): array
    {
        /** @var array<string, string> $post */
        $post = self::load($name, $values);
        $post['verify_sign'] = SslcommerzSignature::sign($post, $post['verify_key'], $password ?? self::STORE_PASSWORD);

        return array_replace($post, $tamper);
    }

    public static function initiatedPayment(Order $order): Payment
    {
        return Payment::query()->where('order_id', $order->id)->orderByDesc('id')->firstOrFail();
    }
}
