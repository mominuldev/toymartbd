<?php

declare(strict_types=1);

namespace Tests\Feature\Payment\Paystation;

use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentProvider;
use Illuminate\Support\Facades\Http;

/**
 * PayStation test fixtures — HAND-AUTHORED, NOT RECORDED.
 *
 * No PayStation merchant account exists yet. Every file in tests/Fixtures/paystation is
 * hand-authored from the shapes in the official documentation as far as it could be read
 * on 2026-11-19 (docs/_research/paystation-api.md). Values are illustrative;
 * `{{placeholders}}` are filled per test. Replace with recorded sandbox responses once a
 * merchant id exists. The network is never called.
 */
final class Fixtures
{
    public const string MERCHANT_ID = '204-00000000000';

    public const string PASSWORD = 'ps-sandbox-secret-0000';

    public const string LIVE_MERCHANT_ID = '204-99999999999';

    public const string LIVE_PASSWORD = 'ps-live-secret-9999';

    public const string SANDBOX = 'https://sandbox.paystation.com.bd';

    public const string LIVE = 'https://api.paystation.com.bd';

    /** Sandbox keys from env/config, the provider row active, unverified and in sandbox mode. */
    public static function configure(bool $active = true): void
    {
        config([
            'payments.paystation.merchant_id' => self::MERCHANT_ID,
            'payments.paystation.password' => self::PASSWORD,
            'payments.paystation.return_base_url' => 'https://shop.example.test',
            'payments.paystation.ipn_allowed_ips' => [],
        ]);
        PaymentProvider::query()->where('code', 'paystation')->update(['is_active' => $active, 'sandbox' => true, 'verified' => false]);
        Http::preventStrayRequests();
    }

    /** Switch the row to live mode with stored live keys. */
    public static function goLive(): void
    {
        $row = PaymentProvider::query()->where('code', 'paystation')->firstOrFail();
        $row->credentials = ['live' => ['merchant_id' => self::LIVE_MERCHANT_ID, 'password' => self::LIVE_PASSWORD]];
        $row->sandbox = false;
        $row->save();
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    public static function load(string $name, array $values = []): array
    {
        $raw = (string) file_get_contents(base_path("tests/Fixtures/paystation/{$name}.json"));
        foreach ($values as $key => $value) {
            $raw = str_replace('{{'.$key.'}}', $value, $raw);
        }

        /** @var array<string, mixed> */
        return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    public static function values(Payment $payment, ?string $amount = null, string $host = self::SANDBOX): array
    {
        $invoice = (string) $payment->transaction_id;

        return [
            'invoice_number' => $invoice,
            'payment_amount' => $amount ?? $payment->amount->toDecimal(),
            'trx_id' => 'PS'.$payment->id.'X7K2',
            'reference' => (string) strstr($invoice, '-P', true),
            'host' => $host,
        ];
    }

    public static function latestPayment(int $orderId): Payment
    {
        return Payment::query()->where('order_id', $orderId)->orderByDesc('id')->firstOrFail();
    }
}
