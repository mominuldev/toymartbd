<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Services;

use App\Domain\Fulfilment\Contracts\FraudLookup;
use App\Domain\Fulfilment\DTOs\FraudLookupResult;
use App\Domain\Fulfilment\Models\CourierFraudLookup;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;

/**
 * The courier-data panel's source (rules 24, 24a, 33b): per provider, what that courier
 * officially reports about a phone's delivery history — or why it reports nothing.
 *
 * Today every provider answers `connected: false`: Steadfast needs a merchant account
 * and verification (`no_merchant_account` / `provider_unverified`), and Pathao publishes
 * no history endpoint (`no_official_api` — we never scrape its portal, rule 24a). The
 * panel renders that as "provider not connected", never as a zero.
 *
 * This is verified EXTERNAL data. It is returned separately and timestamped, and is never
 * merged into our own risk score (rule 24). A connected answer is cached in
 * `courier_fraud_lookups` and reused for `couriers.steadfast.fraud_lookup_ttl_hours`.
 *
 * It may make an external call: never call it inside a database transaction (rule 4).
 * (Not asserted at runtime — the test suite's own wrapping transaction makes a
 * transaction-level guard untestable, as StockLedgerTest notes for the inverse check.)
 */
final readonly class CourierHistoryLookup
{
    public function __construct(private CourierRegistry $registry) {}

    /**
     * @return list<array{provider: string, name: string, name_bn: string|null, connected: bool, reason: string|null, total_parcels: int|null, total_delivered: int|null, total_cancelled: int|null, total_fraud_reports: int|null, delivery_ratio: string|null, cancellation_ratio: string|null, fetched_at: string|null}>
     */
    public function forPhone(PhoneNumber|string $phone): array
    {
        $number = $phone instanceof PhoneNumber ? $phone : PhoneNumber::parse($phone);

        $providers = CourierProvider::query()->orderBy('position')->orderBy('id')->get();

        return array_values($providers->map(fn (CourierProvider $provider): array => $this->present(
            $provider,
            $this->resultFor($provider, $number),
        ))->all());
    }

    private function resultFor(CourierProvider $provider, PhoneNumber $phone): FraudLookupResult
    {
        if (! $this->registry->supports($provider->code)) {
            return FraudLookupResult::notConnected('driver_missing');
        }

        $driver = $this->registry->for($provider->code);
        if (! $driver instanceof FraudLookup) {
            return FraudLookupResult::notConnected('no_official_api');
        }

        $cached = $this->cached($provider, $phone);
        if ($cached !== null) {
            return $cached;
        }

        $result = $driver->fraudLookup($phone);

        if ($result->connected && $result->fetchedAt !== null) {
            CourierFraudLookup::query()->create([
                'provider_id' => $provider->id,
                'phone' => $phone->e164(),
                'total_parcels' => $result->totalParcels,
                'total_delivered' => $result->totalDelivered,
                'total_cancelled' => $result->totalCancelled,
                'total_fraud_reports' => $result->totalFraudReports,
                'delivery_ratio' => $result->deliveryRatio,
                'cancellation_ratio' => $result->cancellationRatio,
                'raw' => $result->raw,
                'source' => 'official_api',
                'fetched_at' => $result->fetchedAt,
            ]);
        }

        return $result;
    }

    private function cached(CourierProvider $provider, PhoneNumber $phone): ?FraudLookupResult
    {
        $ttl = (int) config('couriers.'.$provider->code.'.fraud_lookup_ttl_hours', 24);

        $row = CourierFraudLookup::query()
            ->where('provider_id', $provider->id)
            ->where('phone', $phone->e164())
            ->where('fetched_at', '>=', CarbonImmutable::now()->subHours($ttl))
            ->latest('fetched_at')
            ->latest('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return FraudLookupResult::fromStored(
            $row->total_parcels,
            $row->total_delivered,
            $row->total_cancelled,
            $row->total_fraud_reports,
            $row->delivery_ratio,
            $row->cancellation_ratio,
            $row->raw,
            CarbonImmutable::instance($row->fetched_at),
        );
    }

    /**
     * @return array{provider: string, name: string, name_bn: string|null, connected: bool, reason: string|null, total_parcels: int|null, total_delivered: int|null, total_cancelled: int|null, total_fraud_reports: int|null, delivery_ratio: string|null, cancellation_ratio: string|null, fetched_at: string|null}
     */
    private function present(CourierProvider $provider, FraudLookupResult $result): array
    {
        return [
            'provider' => $provider->code,
            'name' => $provider->name,
            'name_bn' => $provider->name_bn,
            'connected' => $result->connected,
            'reason' => $result->reason,
            'total_parcels' => $result->totalParcels,
            'total_delivered' => $result->totalDelivered,
            'total_cancelled' => $result->totalCancelled,
            'total_fraud_reports' => $result->totalFraudReports,
            'delivery_ratio' => $result->deliveryRatio,
            'cancellation_ratio' => $result->cancellationRatio,
            'fetched_at' => $result->fetchedAt?->toIso8601String(),
        ];
    }
}
