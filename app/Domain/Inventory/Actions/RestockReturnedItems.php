<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\DTOs\StockMovementLine;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\StockLedger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Goods a customer sent back (DATABASE-DESIGN §4.7, PRD I-11). Every returned unit comes
 * in as `customer_return_in`; the damaged ones then go straight out as `damage_out`, so
 * the ledger shows the physical round trip and only resellable units raise on_hand.
 *
 * Both movements carry the line's snapshot unit cost (rule 7) and point at the return.
 * StockLedger locks the levels FOR UPDATE in ascending variant id (rule 9); for one
 * variant the `in` is recorded before the `out` (a stable sort), so a damaged unit never
 * takes on_hand below zero.
 */
final readonly class RestockReturnedItems
{
    public function __construct(private StockLedger $ledger) {}

    /**
     * @param  list<array{variant_id: int, resellable: int, damaged: int, unit_cost: string}>  $lines
     * @return list<StockMovement>
     */
    public function handle(array $lines, string $referenceType, int $referenceId, ?int $userId = null): array
    {
        usort($lines, static fn (array $a, array $b): int => $a['variant_id'] <=> $b['variant_id']);

        $movements = [];
        foreach ($lines as $line) {
            if ($line['resellable'] < 0 || $line['damaged'] < 0) {
                throw new InvalidArgumentException('Returned quantities are never negative.');
            }
            $total = $line['resellable'] + $line['damaged'];
            if ($total === 0) {
                continue;
            }
            $movements[] = new StockMovementLine(
                $line['variant_id'], StockMovementType::CustomerReturnIn, $total, $line['unit_cost'],
                $referenceType, $referenceId, $userId, 'customer return',
            );
            if ($line['damaged'] > 0) {
                $movements[] = new StockMovementLine(
                    $line['variant_id'], StockMovementType::DamageOut, $line['damaged'], $line['unit_cost'],
                    $referenceType, $referenceId, $userId, 'returned damaged — written off',
                );
            }
        }

        return DB::transaction(fn (): array => $this->ledger->record($movements));
    }
}
