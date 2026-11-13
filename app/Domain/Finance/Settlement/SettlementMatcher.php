<?php

declare(strict_types=1);

namespace App\Domain\Finance\Settlement;

use App\Domain\Finance\DTOs\SettlementLine;
use App\Domain\Finance\Enums\SettlementVariance;
use App\Domain\Finance\Services\Receivables;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Matches remittance lines to our shipments by consignment id (per provider) and compares
 * what the courier paid with what the shipment says (§4.5). Reads `shipments` as a table:
 * Finance does not depend on Fulfilment's classes (deptrac), the same seam OrderLedgerSources
 * uses. Produces item rows; it writes nothing and adjusts nothing.
 *
 * Expected for a shipment the courier reports:
 *  - delivered / partially delivered → expected COD = `cod_collected` (the courier's status
 *    report) or else `cod_amount`; expected net = that − charge − COD fee (ours, or the
 *    statement's where our shipment has none yet);
 *  - returned → expected COD 0, net = − charge − return charge;
 *  - anything else → `not_delivered` (nothing is collectable).
 *
 * Variances, first that applies: duplicate_line, unknown_consignment, already_settled,
 * not_delivered, exceeds_receivable, short, over. With a period, delivered COD shipments in
 * it that the statement does not mention come back as `missing` lines.
 */
final readonly class SettlementMatcher
{
    public function __construct(private Receivables $receivables) {}

    /**
     * @param  list<SettlementLine>  $lines
     * @return list<array<string, mixed>> courier_settlement_items attributes
     */
    public function match(int $providerId, array $lines, ?CarbonImmutable $periodStart = null, ?CarbonImmutable $periodEnd = null): array
    {
        $ids = array_values(array_unique(array_map(static fn (SettlementLine $l): string => $l->consignmentId, $lines)));
        $shipments = DB::table('shipments')->where('provider_id', $providerId)->whereIn('consignment_id', $ids ?: [''])
            ->get()->keyBy('consignment_id');
        $settled = self::settledShipmentIds($shipments->pluck('id')->all());

        $rows = [];
        $seen = [];
        foreach ($lines as $line) {
            $row = [
                'line_no' => $line->lineNo,
                'consignment_id' => $line->consignmentId,
                'cod_amount' => $line->codAmount,
                'charges' => $line->charges,
                'cod_fee' => $line->codFee,
                'return_charge' => $line->returnCharge,
                'net' => $line->netAmount(),
                'shipment_id' => null,
                'order_id' => null,
                'expected_cod' => null,
                'expected_net' => null,
                'variance' => Money::zero(),
                'is_matched' => false,
            ];
            $duplicate = isset($seen[$line->consignmentId]);
            $seen[$line->consignmentId] = true;
            $shipment = $shipments->get($line->consignmentId);

            if ($duplicate) {
                $rows[] = $row + ['variance_type' => SettlementVariance::DuplicateLine];

                continue;
            }
            if ($shipment === null) {
                $rows[] = $row + ['variance_type' => SettlementVariance::UnknownConsignment];

                continue;
            }

            $row['shipment_id'] = (int) $shipment->id;
            $row['order_id'] = (int) $shipment->order_id;
            $row['is_matched'] = true;
            [$expectedCod, $expectedNet] = self::expected($shipment, $line);
            $row['expected_cod'] = $expectedCod;
            $row['expected_net'] = $expectedNet;
            if ($expectedNet !== null) {
                $row['variance'] = $row['net']->minus($expectedNet);
            }

            $rows[] = $row + ['variance_type' => $this->variance($row, in_array((int) $shipment->id, $settled, true))];
        }

        if ($periodStart !== null && $periodEnd !== null) {
            array_push($rows, ...$this->missing($providerId, array_keys($seen), $periodStart, $periodEnd));
        }

        return $rows;
    }

    /** @param  array<string, mixed>  $row */
    private function variance(array $row, bool $settled): SettlementVariance
    {
        /** @var Money|null $expectedCod */
        $expectedCod = $row['expected_cod'];
        /** @var Money $cod */
        $cod = $row['cod_amount'];
        /** @var Money $variance */
        $variance = $row['variance'];

        if ($settled) {
            return SettlementVariance::AlreadySettled;
        }
        if ($expectedCod === null) {
            return SettlementVariance::NotDelivered;
        }
        if ($cod->greaterThan(Money::zero()) && is_int($row['order_id'])
            && $cod->greaterThan($this->receivables->forOrder($row['order_id']))) {
            return SettlementVariance::ExceedsReceivable;
        }
        if ($cod->lessThan($expectedCod) || $variance->isNegative()) {
            return SettlementVariance::Short;
        }
        if ($cod->greaterThan($expectedCod) || $variance->greaterThan(Money::zero())) {
            return SettlementVariance::Over;
        }

        return SettlementVariance::None;
    }

    /** @return array{0: Money|null, 1: Money|null} expected COD, expected net */
    private static function expected(object $shipment, SettlementLine $line): array
    {
        $status = (string) ($shipment->status ?? '');
        $charge = self::money($shipment->charge ?? null) ?? $line->charges;
        if (in_array($status, ['delivered', 'partially_delivered'], true)) {
            $cod = self::money($shipment->cod_collected ?? null) ?? self::money($shipment->cod_amount ?? null) ?? Money::zero();
            $fee = self::money($shipment->cod_fee ?? null) ?? $line->codFee;

            return [$cod, $cod->minus($charge)->minus($fee)];
        }
        if ($status === 'returned') {
            $return = self::money($shipment->return_charge ?? null) ?? $line->returnCharge;

            return [Money::zero(), Money::zero()->minus($charge)->minus($return)];
        }

        return [null, null];
    }

    /**
     * Delivered COD shipments in the period that the statement did not mention.
     *
     * @param  list<string>  $mentioned
     * @return list<array<string, mixed>>
     */
    private function missing(int $providerId, array $mentioned, CarbonImmutable $start, CarbonImmutable $end): array
    {
        [$from] = DhakaDate::utcBounds($start->toDateString());
        [, $to] = DhakaDate::utcBounds($end->toDateString());
        $candidates = DB::table('shipments')->where('provider_id', $providerId)
            ->whereIn('status', ['delivered', 'partially_delivered'])
            ->where('cod_amount', '>', 0)
            ->whereNotNull('consignment_id')
            ->whereNotIn('consignment_id', $mentioned ?: [''])
            ->where('delivered_at', '>=', $from)->where('delivered_at', '<', $to)
            ->get();
        $settled = self::settledShipmentIds($candidates->pluck('id')->all());

        $rows = [];
        foreach ($candidates as $s) {
            if (in_array((int) $s->id, $settled, true)) {
                continue;
            }
            $cod = self::money($s->cod_collected ?? null) ?? self::money($s->cod_amount) ?? Money::zero();
            $net = $cod->minus(self::money($s->charge ?? null) ?? Money::zero())->minus(self::money($s->cod_fee ?? null) ?? Money::zero());
            $rows[] = [
                'line_no' => null,
                'consignment_id' => (string) $s->consignment_id,
                'cod_amount' => Money::zero(),
                'charges' => Money::zero(),
                'cod_fee' => Money::zero(),
                'return_charge' => Money::zero(),
                'net' => Money::zero(),
                'shipment_id' => (int) $s->id,
                'order_id' => (int) $s->order_id,
                'expected_cod' => $cod,
                'expected_net' => $net,
                'variance' => Money::zero()->minus($net),
                'is_matched' => false,
                'variance_type' => SettlementVariance::Missing,
            ];
        }

        return $rows;
    }

    /**
     * Shipments already booked in an earlier settlement.
     *
     * @param  list<mixed>  $shipmentIds
     * @return list<int>
     */
    private static function settledShipmentIds(array $shipmentIds): array
    {
        if ($shipmentIds === []) {
            return [];
        }

        return array_map(intval(...), DB::table('courier_settlement_items')->whereIn('shipment_id', $shipmentIds)
            ->whereNotNull('booked_at')->pluck('shipment_id')->all());
    }

    private static function money(mixed $value): ?Money
    {
        return is_numeric($value) ? Money::of(number_format((float) $value, 2, '.', '')) : null;
    }
}
