<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\DTOs\SettlementLine;
use App\Domain\Finance\Enums\SettlementStatus;
use App\Domain\Finance\Enums\SettlementVariance;
use App\Domain\Finance\Exceptions\SettlementRefused;
use App\Domain\Finance\Models\CourierSettlement;
use App\Domain\Finance\Models\CourierSettlementItem;
use App\Domain\Finance\Settlement\SettlementMatcher;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Imports one courier remittance (§4.5) — from a portal export or typed in — and matches
 * every line to our shipments. Writes the settlement and its lines (status `received`) and
 * NOTHING to the ledger: money is booked only when a person confirms
 * ({@see ConfirmCourierSettlement}). The same (provider, reference) twice is a 409.
 */
final readonly class ImportCourierSettlement
{
    public function __construct(private SettlementMatcher $matcher) {}

    /**
     * @param  list<SettlementLine>  $lines
     *
     * @throws SettlementRefused
     */
    public function handle(
        string $providerCode,
        string $reference,
        CarbonImmutable $receivedAt,
        array $lines,
        string $source,
        ?CarbonImmutable $periodStart = null,
        ?CarbonImmutable $periodEnd = null,
        ?string $note = null,
        ?string $statementPath = null,
        ?int $userId = null,
    ): CourierSettlement {
        $providerId = DB::table('courier_providers')->where('code', $providerCode)->value('id');
        if (! is_numeric($providerId)) {
            throw SettlementRefused::unknownProvider($providerCode);
        }
        $providerId = (int) $providerId;

        return DB::transaction(function () use ($providerId, $reference, $receivedAt, $lines, $source, $periodStart, $periodEnd, $note, $statementPath, $userId): CourierSettlement {
            if (CourierSettlement::query()->where('provider_id', $providerId)->where('reference', $reference)->lockForUpdate()->exists()) {
                throw SettlementRefused::duplicate($reference);
            }

            $rows = $this->matcher->match($providerId, $lines, $periodStart, $periodEnd);
            $sum = static fn (string $key): Money => array_reduce(
                array_filter($rows, static fn (array $r): bool => $r['line_no'] !== null),
                static fn (Money $c, array $r): Money => $c->plus($r[$key]),
                Money::zero(),
            );

            $settlement = CourierSettlement::query()->create([
                'provider_id' => $providerId,
                'reference' => $reference,
                'period_start' => $periodStart?->toDateString(),
                'period_end' => $periodEnd?->toDateString(),
                'cod_collected' => $sum('cod_amount'),
                'delivery_charges' => $sum('charges'),
                'cod_fees' => $sum('cod_fee'),
                'return_charges' => $sum('return_charge'),
                'adjustments' => Money::zero(),
                'net_received' => $sum('net'),
                'received_at' => $receivedAt->toDateString(),
                'status' => SettlementStatus::Received,
                'source' => $source,
                'variance_count' => count(array_filter($rows, static fn (array $r): bool => $r['variance_type'] !== SettlementVariance::None)),
                'statement_path' => $statementPath,
                'note' => $note,
                'imported_by' => $userId,
            ]);
            foreach ($rows as $row) {
                CourierSettlementItem::query()->create(['settlement_id' => $settlement->id] + $row);
            }

            return $settlement;
        });
    }
}
