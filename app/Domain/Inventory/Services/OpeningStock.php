<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\DTOs\StockMovementLine;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockMovement;

/**
 * Opening stock for a newly created product's variants: one `opening_balance` movement
 * per variant with a quantity > 0, at the default location unless one is given (a
 * branch opening with stock already on its shelves). A variant that already has any
 * movement at that location is skipped — an opening balance is recorded once per
 * location; every later change is a purchase, sale, adjustment or transfer. No
 * transaction of its own.
 */
final readonly class OpeningStock
{
    public function __construct(private StockLedger $ledger) {}

    /**
     * @param  array<int, int>  $quantities  variant id → opening on-hand (0 = record nothing)
     * @return list<StockMovement>
     */
    public function record(Product $product, array $quantities, ?int $userId = null, ?int $locationId = null): array
    {
        $quantities = array_filter($quantities, static fn (int $q): bool => $q > 0);
        if ($quantities === []) {
            return [];
        }

        $locationId ??= StockLocation::defaultId();
        $alreadyOpened = StockMovement::query()
            ->whereIn('variant_id', array_keys($quantities))
            ->where('location_id', $locationId)
            ->distinct()
            ->pluck('variant_id')
            ->all();

        $lines = [];
        foreach ($quantities as $variantId => $quantity) {
            if (in_array($variantId, $alreadyOpened, true)) {
                continue;
            }
            $lines[] = new StockMovementLine(
                $variantId,
                StockMovementType::OpeningBalance,
                $quantity,
                referenceType: 'product',
                referenceId: $product->id,
                userId: $userId,
            );
        }

        return $this->ledger->record($lines, $locationId);
    }
}
