<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One variant line of a stock adjustment: on_hand before and after, read under the row
 * lock, and the unit cost the units moved at.
 *
 * @property int $id
 * @property int $adjustment_id
 * @property int $variant_id
 * @property int|null $requested_change
 * @property int|null $counted
 * @property int $quantity_before
 * @property int $quantity_after
 * @property string|null $unit_cost
 * @property Carbon|null $created_at
 * @property-read ProductVariant $variant
 */
final class StockAdjustmentItem extends Model
{
    public const null UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['adjustment_id', 'variant_id', 'requested_change', 'counted', 'quantity_before', 'quantity_after', 'unit_cost'];

    public function change(): int
    {
        return $this->quantity_after - $this->quantity_before;
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'adjustment_id' => 'integer',
            'variant_id' => 'integer',
            'requested_change' => 'integer',
            'counted' => 'integer',
            'quantity_before' => 'integer',
            'quantity_after' => 'integer',
            'unit_cost' => 'decimal:4',
        ];
    }
}
