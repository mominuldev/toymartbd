<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTOs\StockMovementLine;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Exceptions\StockLedgerViolation;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of stock_movements and of stock_levels.on_hand (DATABASE-DESIGN §3.2,
 * §7.1). Every call appends movements and moves the projection by the same amount, so
 * SUM(quantity) = on_hand always holds.
 *
 * It owns no transaction (rule 3): the calling Action does, and every method refuses
 * to run outside one. Levels are locked with SELECT … FOR UPDATE in ascending variant
 * id order (rule 9), so two concurrent writers over overlapping variants queue rather
 * than deadlock. All lines are checked before any is written — a batch is all or
 * nothing.
 */
final readonly class StockLedger
{
    public function __construct(private StockLevelLocks $locks) {}

    /**
     * @param  list<StockMovementLine>  $lines
     * @return list<StockMovement> in ascending variant id order
     *
     * @throws InsufficientStock when a line would take on_hand below zero and its type forbids it
     */
    public function record(array $lines, ?int $locationId = null): array
    {
        if (DB::transactionLevel() === 0) {
            throw StockLedgerViolation::outsideTransaction();
        }
        if ($lines === []) {
            return [];
        }

        $locationId ??= StockLocation::defaultId();

        usort($lines, static fn (StockMovementLine $a, StockMovementLine $b): int => $a->variantId <=> $b->variantId);
        foreach ($lines as $line) {
            if ($line->quantity <= 0) {
                throw StockLedgerViolation::nonPositiveQuantity();
            }
        }

        $levels = $this->locks->lock(array_map(static fn (StockMovementLine $l): int => $l->variantId, $lines), $locationId);

        // Check every line against the running balance before writing anything.
        $balances = array_map(static fn (StockLevel $level): int => $level->on_hand, $levels);
        $short = [];
        foreach ($lines as $line) {
            $next = $balances[$line->variantId] + $line->type->sign() * $line->quantity;
            if ($next < 0 && ! $line->type->allowsNegativeBalance()) {
                $short[$line->variantId] = ['requested' => $line->quantity, 'available' => max(0, $balances[$line->variantId])];
            }
            $balances[$line->variantId] = $next;
        }
        if ($short !== []) {
            throw InsufficientStock::forLines($short);
        }

        $movements = [];
        foreach ($lines as $line) {
            $level = $levels[$line->variantId];
            $level->on_hand += $line->type->sign() * $line->quantity;
            $level->save();

            $movements[] = StockMovement::query()->create([
                'variant_id' => $line->variantId,
                'location_id' => $locationId,
                'type' => $line->type,
                'quantity' => $line->type->sign() * $line->quantity,
                'balance_after' => $level->on_hand,
                'unit_cost' => $line->unitCost,
                'reference_type' => $line->referenceType,
                'reference_id' => $line->referenceId,
                'user_id' => $line->userId,
                'note' => $line->note,
            ]);
        }

        $this->locks->announce(array_keys($levels));

        return $movements;
    }

    /** Convenience for one line. */
    public function recordOne(int $variantId, StockMovementType $type, int $quantity, ?int $userId = null, ?string $note = null): StockMovement
    {
        return $this->record([new StockMovementLine($variantId, $type, $quantity, userId: $userId, note: $note)])[0];
    }
}
