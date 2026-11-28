<?php

declare(strict_types=1);

namespace Tests\Feature\Payment\Bkash;

use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * bKash test fixtures — HAND-AUTHORED, NOT RECORDED.
 *
 * No bKash merchant account (and so no sandbox key) exists. Every file in tests/Fixtures/bkash
 * is hand-authored from the sample requests and responses on developer.bka.sh, read
 * 2026-11-28 (docs/_research/bkash-api.md). Values are illustrative; `{{placeholders}}` are
 * filled per call. Replace with recorded sandbox responses once bKash issues sandbox keys.
 *
 * {@see self::sandbox()} installs a small stateful simulator of the documented calls (grant,
 * refresh, create, execute, query, refund, refund status) on Http::fake. The network is never
 * called.
 */
final class Fixtures
{
    public const string USERNAME = 'sandboxTokenizedUser02';

    public const string PASSWORD = 'sandboxTokenizedUser02@12345';

    public const string APP_KEY = '4f6o0cjiki2rfm34kfdadl1eqq';

    public const string APP_SECRET = '2is7hdktrekvrbljjh44ll3d9l1dtjo4pasmjvs5vl5qr3fug4b';

    public const string SANDBOX = 'https://tokenized.sandbox.bka.sh/v2/tokenized-checkout';

    public const string LIVE = 'https://tokenized.live.example.test/v2/tokenized-checkout';

    /** Sandbox keys from env/config, the provider row active, unverified and in sandbox mode. */
    public static function configure(bool $active = true): void
    {
        config([
            'payments.bkash.username' => self::USERNAME,
            'payments.bkash.password' => self::PASSWORD,
            'payments.bkash.app_key' => self::APP_KEY,
            'payments.bkash.app_secret' => self::APP_SECRET,
            'payments.bkash.hosts.live' => null,
            'payments.bkash.return_base_url' => 'https://shop.example.test',
            'payments.bkash.authorization' => 'plain',
        ]);
        PaymentProvider::query()->where('code', 'bkash')->update(['is_active' => $active, 'sandbox' => true, 'verified' => false]);
        Http::preventStrayRequests();
    }

    /** Switch the row to live mode with stored live keys and a live base URL. */
    public static function goLive(): void
    {
        config(['payments.bkash.hosts.live' => self::LIVE]);
        $row = PaymentProvider::query()->where('code', 'bkash')->firstOrFail();
        $row->credentials = ['live' => ['username' => 'live-user-0001', 'password' => 'live-pass-0001', 'app_key' => 'live-app-key-0001', 'app_secret' => 'live-app-secret-0001']];
        $row->sandbox = false;
        $row->save();
    }

    public static function sandbox(string $base = self::SANDBOX): BkashSandbox
    {
        $sandbox = new BkashSandbox($base);
        Http::fake([$base.'/*' => fn (Request $request) => $sandbox->handle($request)]);

        return $sandbox;
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    public static function load(string $name, array $values = []): array
    {
        $raw = (string) file_get_contents(base_path("tests/Fixtures/bkash/{$name}.json"));
        foreach ($values as $key => $value) {
            $raw = str_replace('{{'.$key.'}}', $value, $raw);
        }

        /** @var array<string, mixed> */
        return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    }

    public static function latestPayment(int $orderId): Payment
    {
        return Payment::query()->where('order_id', $orderId)->orderByDesc('id')->firstOrFail();
    }
}
