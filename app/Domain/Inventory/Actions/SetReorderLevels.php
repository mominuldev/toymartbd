<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Services\StockLevelLocks;
use App\Domain\Inventory\Services\StockOverview;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sets the reorder point and reorder quantity of variants at one location — planning
 * figures on the level row, never a stock quantity: on_hand and reserved are not
 * touched and no movement is written. The rows are locked in ascending variant id
 * order like every other level write (rule 9), so it cannot interleave with a sale.
 * Used by the demo seeder now and by the reorder-point editor later.
 */
final readonly class SetReorderLevels
{
    public function __construct(private StockLevelLocks $locks) {}

    /**
     * @param  array<int, array{point: int, quantity: int}>  $levels  variant id → reorder point and quantity
     * @return array<int, StockLevel> variant id → updated level
     *
     * @throws InvalidArgumentException on a negative figure
     */
    public function handle(int $locationId, array $levels): array
    {
        foreach ($levels as $level) {
            if ($level['point'] < 0 || $level['quantity'] < 0) {
                throw new InvalidArgumentException('Reorder point and quantity cannot be negative.');
            }
        }
        if ($levels === []) {
            return [];
        }

        $updated = DB::transaction(function () use ($locationId, $levels): array {
            $locked = $this->locks->lock(array_keys($levels), $locationId);
            foreach ($locked as $variantId => $level) {
                $level->reorder_point = $levels[$variantId]['point'];
                $level->reorder_quantity = $levels[$variantId]['quantity'];
                $level->save();
            }

            return $locked;
        });

        StockOverview::forgetLowCounts();

        return $updated;
    }
}
