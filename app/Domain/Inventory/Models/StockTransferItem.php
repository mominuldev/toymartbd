<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One variant line of a stock transfer: units moved and the unit cost they carried.
 *
 * @property int $id
 * @property int $transfer_id
 * @property int $variant_id
 * @property int $quantity
 * @property string|null $unit_cost
 * @property Carbon|null $created_at
 * @property-read ProductVariant $variant
 */
final class StockTransferItem extends Model
{
    public const null UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['transfer_id', 'variant_id', 'quantity', 'unit_cost'];

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'transfer_id' => 'integer',
            'variant_id' => 'integer',
            'quantity' => 'integer',
            'unit_cost' => 'decimal:4',
        ];
    }
}
