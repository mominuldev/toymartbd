<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\DTOs\StockMovementLine;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockMovement;

/**
 * Opening stock for a newly created product's variants: one `opening_balance` movement
 * per variant with a quantity > 0. A variant that already has any movement is skipped —
 * an opening balance is recorded once; every later change is a purchase, sale or
 * adjustment (stock adjustments are Phase 5, P5-INV). No transaction of its own.
 */
final readonly class OpeningStock
{
    public function __construct(private StockLedger $ledger) {}

    /**
     * @param  array<int, int>  $quantities  variant id → opening on-hand (0 = record nothing)
     * @return list<StockMovement>
     */
    public function record(Product $product, array $quantities, ?int $userId = null): array
    {
        $quantities = array_filter($quantities, static fn (int $q): bool => $q > 0);
        if ($quantities === []) {
            return [];
        }

        $alreadyOpened = StockMovement::query()->whereIn('variant_id', array_keys($quantities))->distinct()->pluck('variant_id')->all();

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

        return $this->ledger->record($lines);
    }
}
