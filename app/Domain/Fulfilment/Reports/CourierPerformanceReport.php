<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Reports;

use App\Domain\Customer\Models\District;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\Shipment;
use App\Support\DhakaDate;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Courier performance (P6; 06-admin-feature-checklist §2.3 "delivery rate, avg days, return
 * rate by provider × district"). Read-only; every figure is computed here (rule 1).
 *
 * **Cohort.** The shipments handed to a courier during the period: `booked_at` (or, for a
 * bulk-submitted parcel the courier has not confirmed yet, `pickup_requested_at`) on a
 * Dhaka date from `from` to `to` inclusive (rule 11). Pending and failed bookings never
 * reached a courier and are not counted. Each shipment is reported in its CURRENT status,
 * so a recent period still has parcels in flight.
 *
 *   booked ............. every shipment in the cohort
 *   delivered .......... delivered + partially delivered (`partially_delivered` also alone)
 *   returned / cancelled / in_flight (submitted, booked, picked up, in transit, out for
 *                        delivery, on hold)
 *   delivery_rate ...... delivered ÷ (delivered + returned) × 100, 2 dp — the share of
 *                        parcels with an outcome that reached the customer; null when none has
 *   return_rate ........ returned ÷ (delivered + returned) × 100, 2 dp
 *   average_days_to_deliver  mean of (delivered_at − booked_at) in days, 1 dp, delivered only
 *   average_charge ..... mean delivery charge over shipments with a known charge, to the poisha
 *   cod_expected ....... Σ cod_amount, cancelled excluded
 *   cod_collected ...... what the courier collected from customers: `cod_collected` when the
 *                        courier reported it; a fully delivered parcel without it counts its
 *                        cod_amount (delivery means the COD was taken); a partial without it, 0
 *   cod_outstanding .... Σ cod_amount still in flight — COD not yet collected from a customer
 *
 * Remittance by the courier to us is the settlement's business (`cod_received`), not this
 * report's: "collected" here is the courier's hand, not our bank (rule 10).
 */
final class CourierPerformanceReport
{
    /** @var list<string> */
    private const array IN_FLIGHT = ['submitted', 'booked', 'picked_up', 'in_transit', 'out_for_delivery', 'on_hold'];

    /**
     * @param  string  $from  Y-m-d Dhaka date, inclusive
     * @param  string  $to  Y-m-d Dhaka date, inclusive
     * @return array{from: string, to: string, provider: string|null, totals: array<string, mixed>, providers: list<array<string, mixed>>, districts: list<array<string, mixed>>}
     */
    public function report(string $from, string $to, ?string $providerCode = null): array
    {
        [$start] = DhakaDate::utcBounds($from);
        [, $end] = DhakaDate::utcBounds($to);

        $handedOver = DB::raw('COALESCE(shipments.booked_at, shipments.pickup_requested_at)');
        $query = Shipment::query()
            ->leftJoin('orders', 'orders.id', '=', 'shipments.order_id')
            ->whereNotIn('shipments.status', [ShipmentStatus::Pending->value, ShipmentStatus::Failed->value])
            ->where($handedOver, '>=', $start)
            ->where($handedOver, '<', $end)
            ->select([
                'shipments.id', 'shipments.provider_id', 'shipments.status', 'shipments.booked_at', 'shipments.pickup_requested_at',
                'shipments.delivered_at', 'shipments.charge', 'shipments.cod_amount', 'shipments.cod_collected',
                'orders.shipping_district_id as district_id',
            ])
            ->orderBy('shipments.id');

        $provider = null;
        if ($providerCode !== null) {
            $provider = CourierProvider::query()->where('code', $providerCode)->first();
            $query->where('shipments.provider_id', $provider->id ?? 0);
        }

        $totals = self::blank();
        /** @var array<int, array<string, int>> $byProvider */
        $byProvider = [];
        /** @var array<int, array<string, int>> $byDistrict */
        $byDistrict = [];

        foreach ($query->toBase()->cursor() as $row) {
            /** @var object{provider_id: int|string, status: string, booked_at: string|null, pickup_requested_at: string|null, delivered_at: string|null, charge: string|null, cod_amount: string|null, cod_collected: string|null, district_id: int|string|null} $row */
            $line = self::measure($row);
            $totals = self::add($totals, $line);
            $p = (int) $row->provider_id;
            $byProvider[$p] = self::add($byProvider[$p] ?? self::blank(), $line);
            $d = $row->district_id === null ? 0 : (int) $row->district_id;
            $byDistrict[$d] = self::add($byDistrict[$d] ?? self::blank(), $line);
        }

        $providers = CourierProvider::query()->whereKey(array_keys($byProvider))->get()->keyBy('id');
        $providerRows = [];
        foreach ($byProvider as $id => $sums) {
            /** @var CourierProvider|null $model */
            $model = $providers->get($id);
            $providerRows[] = [
                'provider' => ['id' => $id, 'code' => $model->code ?? '', 'name' => $model->name ?? '', 'name_bn' => $model?->name_bn],
                ...self::present($sums),
            ];
        }
        usort($providerRows, static fn (array $a, array $b): int => [$b['booked'], $a['provider']['name']] <=> [$a['booked'], $b['provider']['name']]);

        $districts = District::query()->whereKey(array_keys($byDistrict))->get()->keyBy('id');
        $districtRows = [];
        foreach ($byDistrict as $id => $sums) {
            /** @var District|null $model */
            $model = $id === 0 ? null : $districts->get($id);
            $districtRows[] = [
                'district' => $model === null ? null : ['id' => $model->id, 'name' => $model->name, 'name_bn' => $model->name_bn],
                ...self::present($sums),
            ];
        }
        usort($districtRows, static fn (array $a, array $b): int => [$b['booked'], $a['district']['name'] ?? '~'] <=> [$a['booked'], $b['district']['name'] ?? '~']);

        return [
            'from' => $from,
            'to' => $to,
            'provider' => $provider?->code,
            'totals' => self::present($totals),
            'providers' => $providerRows,
            'districts' => $districtRows,
        ];
    }

    /** @return array<string, int> */
    private static function blank(): array
    {
        return [
            'booked' => 0, 'delivered' => 0, 'partially_delivered' => 0, 'returned' => 0, 'cancelled' => 0, 'in_flight' => 0,
            'delivery_seconds' => 0, 'timed' => 0, 'charged' => 0, 'charge_poisha' => 0,
            'cod_expected' => 0, 'cod_collected' => 0, 'cod_outstanding' => 0,
        ];
    }

    /**
     * One shipment's contribution, in integers (poisha, seconds) so sums are exact.
     *
     * @param  object{status: string, booked_at: string|null, pickup_requested_at: string|null, delivered_at: string|null, charge: string|null, cod_amount: string|null, cod_collected: string|null}  $row
     * @return array<string, int>
     */
    private static function measure(object $row): array
    {
        $m = self::blank();
        $status = $row->status;
        $cod = Money::of($row->cod_amount ?? '0')->toPoisha();
        $delivered = in_array($status, [ShipmentStatus::Delivered->value, ShipmentStatus::PartiallyDelivered->value], true);

        $m['booked'] = 1;
        $m['delivered'] = $delivered ? 1 : 0;
        $m['partially_delivered'] = $status === ShipmentStatus::PartiallyDelivered->value ? 1 : 0;
        $m['returned'] = $status === ShipmentStatus::Returned->value ? 1 : 0;
        $m['cancelled'] = $status === ShipmentStatus::Cancelled->value ? 1 : 0;
        $m['in_flight'] = in_array($status, self::IN_FLIGHT, true) ? 1 : 0;

        $handedOver = $row->booked_at ?? $row->pickup_requested_at;
        if ($delivered && $row->delivered_at !== null && $handedOver !== null) {
            $m['delivery_seconds'] = max(0, (int) (strtotime($row->delivered_at.' UTC') - strtotime($handedOver.' UTC')));
            $m['timed'] = 1;
        }
        if ($row->charge !== null) {
            $m['charged'] = 1;
            $m['charge_poisha'] = Money::of($row->charge)->toPoisha();
        }
        if ($status !== ShipmentStatus::Cancelled->value) {
            $m['cod_expected'] = $cod;
        }
        if ($delivered) {
            $m['cod_collected'] = $row->cod_collected !== null
                ? Money::of($row->cod_collected)->toPoisha()
                : ($status === ShipmentStatus::Delivered->value ? $cod : 0);
        }
        if ($m['in_flight'] === 1) {
            $m['cod_outstanding'] = $cod;
        }

        return $m;
    }

    /**
     * @param  array<string, int>  $a
     * @param  array<string, int>  $b
     * @return array<string, int>
     */
    private static function add(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            $a[$key] = ($a[$key] ?? 0) + $value;
        }

        return $a;
    }

    /**
     * @param  array<string, int>  $s
     * @return array{booked: int, delivered: int, partially_delivered: int, returned: int, cancelled: int, in_flight: int, delivery_rate: string|null, return_rate: string|null, average_days_to_deliver: string|null, average_charge: string|null, cod_expected: string, cod_collected: string, cod_outstanding: string}
     */
    private static function present(array $s): array
    {
        $outcomes = $s['delivered'] + $s['returned'];

        return [
            'booked' => $s['booked'],
            'delivered' => $s['delivered'],
            'partially_delivered' => $s['partially_delivered'],
            'returned' => $s['returned'],
            'cancelled' => $s['cancelled'],
            'in_flight' => $s['in_flight'],
            'delivery_rate' => $outcomes === 0 ? null : self::ratio($s['delivered'] * 100, $outcomes, 2),
            'return_rate' => $outcomes === 0 ? null : self::ratio($s['returned'] * 100, $outcomes, 2),
            'average_days_to_deliver' => $s['timed'] === 0 ? null : self::ratio($s['delivery_seconds'], $s['timed'] * 86400, 1),
            'average_charge' => $s['charged'] === 0 ? null : Money::fromPoisha(intdiv(2 * $s['charge_poisha'] + $s['charged'], 2 * $s['charged']))->toDecimal(),
            'cod_expected' => Money::fromPoisha($s['cod_expected'])->toDecimal(),
            'cod_collected' => Money::fromPoisha($s['cod_collected'])->toDecimal(),
            'cod_outstanding' => Money::fromPoisha($s['cod_outstanding'])->toDecimal(),
        ];
    }

    /** $numerator ÷ $denominator as a decimal string, rounded half-up to $places (both ≥ 0). */
    private static function ratio(int $numerator, int $denominator, int $places): string
    {
        $scale = 10 ** $places;
        $scaled = intdiv(2 * $numerator * $scale + $denominator, 2 * $denominator);

        return intdiv($scaled, $scale).($places > 0 ? '.'.str_pad((string) ($scaled % $scale), $places, '0', STR_PAD_LEFT) : '');
    }
}
