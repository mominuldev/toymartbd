<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Fulfilment\DTOs\CourierLocationRef;
use App\Domain\Fulfilment\DTOs\ShipmentRequest;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Support\Money;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

/**
 * Pathao fixtures (tests/Fixtures/Pathao/). Files WITHOUT a prefix are REAL sandbox
 * responses captured 2026-11-13 from https://courier-api-sandbox.pathao.com with the
 * published test account — tokens replaced by `fixture-*` placeholders, lists trimmed.
 * Files prefixed `documented.` were NOT observed (they would have created orders or need a
 * configured webhook): they follow research agent3 §5.4–5.5 verbatim.
 *
 * Tests never reach the network: every test calls Http::preventStrayRequests().
 */
final class PathaoFixtures
{
    public const string HOST = 'https://courier-api-sandbox.pathao.com/aladdin/api/v1';

    public const string WEBHOOK_SECRET = 'pathao-webhook-test-secret';

    /** @return array<string, mixed> */
    public static function json(string $name): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/Pathao/{$name}.json")), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function raw(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/Pathao/{$name}.json"));
    }

    public static function response(string $name, int $status = 200): PromiseInterface
    {
        return Http::response(self::raw($name), $status, ['Content-Type' => 'application/json']);
    }

    /** Give the seeded Pathao row test credentials (stored encrypted, like the admin does). */
    public static function configure(): CourierProvider
    {
        $provider = CourierProvider::query()->where('code', 'pathao')->firstOrFail();
        $provider->forceFill([
            'credentials' => ['client_id' => 'test-client', 'client_secret' => 'test-client-secret', 'username' => 'merchant@example.test', 'password' => 'test-password', 'store_id' => '39'],
            'webhook_secret' => self::WEBHOOK_SECRET,
        ])->save();

        return $provider;
    }

    public static function request(?CourierLocationRef $location = new CourierLocationRef('1', '298', null), string $cod = '500.00', int $grams = 1500): ShipmentRequest
    {
        return new ShipmentRequest(
            shipmentId: 7,
            merchantOrderRef: 'TMB-26-00042',
            recipientName: 'নুসরাত জাহান',
            recipientPhone: '+8801712046318',
            addressLine: 'বাড়ি 7, রোড 3, সেকশন 10, মিরপুর',
            landmark: 'মিরপুর 10 গোলচত্বরের কাছে',
            districtId: 47,
            upazilaId: null,
            courierLocation: $location,
            codAmount: Money::of($cod),
            declaredValue: Money::of($cod),
            weightGrams: $grams,
            itemCount: 2,
            itemDescription: 'Toys: LEGO Classic, Rubik cube',
            note: 'Call before delivery',
        );
    }
}
