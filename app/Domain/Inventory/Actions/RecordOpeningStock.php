<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\OpeningStock;
use Illuminate\Support\Facades\DB;

/**
 * Opening stock for an existing product's variants (seeders, a future import). Each
 * variant gets an `opening_balance` movement once; one that already has movements is
 * skipped, so re-running is safe.
 */
final readonly class RecordOpeningStock
{
    public function __construct(private OpeningStock $openingStock) {}

    /**
     * @param  array<int, int>  $quantities  variant id → opening on-hand
     * @return list<StockMovement>
     */
    public function handle(Product $product, array $quantities, ?int $userId = null): array
    {
        return DB::transaction(fn (): array => $this->openingStock->record($product, $quantities, $userId));
    }
}
