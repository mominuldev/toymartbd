<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Current stock for one variant at one location (DATABASE-DESIGN §3.2) — a projection
 * of stock_movements. Written only by StockLedger and StockReservations, under a row
 * lock (rule 9). `available` is derived: on_hand - reserved.
 *
 * @property int $id
 * @property int $variant_id
 * @property int $location_id
 * @property int $on_hand
 * @property int $reserved
 * @property int $incoming
 * @property int $reorder_point
 * @property int $reorder_quantity
 * @property string $avg_cost
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProductVariant $variant
 */
final class StockLevel extends Model
{
    /** @var list<string> */
    protected $fillable = ['variant_id', 'location_id', 'on_hand', 'reserved', 'incoming', 'reorder_point', 'reorder_quantity', 'avg_cost'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'on_hand' => 0,
        'reserved' => 0,
        'incoming' => 0,
        'reorder_point' => 0,
        'reorder_quantity' => 0,
        'avg_cost' => '0.0000',
    ];

    public function available(): int
    {
        return $this->on_hand - $this->reserved;
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
            'variant_id' => 'integer',
            'location_id' => 'integer',
            'on_hand' => 'integer',
            'reserved' => 'integer',
            'incoming' => 'integer',
            'reorder_point' => 'integer',
            'reorder_quantity' => 'integer',
        ];
    }
}
