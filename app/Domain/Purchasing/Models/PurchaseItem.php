<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Catalog\Models\ProductVariant;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One PO line (DATABASE-DESIGN §3.2). `unit_cost` and `landed_unit_cost` are 4-dp
 * decimal strings (unit costs, like stock_movements.unit_cost); taka amounts use Money.
 *
 * @property int $id
 * @property int $purchase_id
 * @property int $variant_id
 * @property int $quantity_ordered
 * @property int $quantity_received
 * @property string $unit_cost
 * @property string|null $landed_unit_cost
 * @property Money $discount
 * @property Money $line_total
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProductVariant $variant
 */
final class PurchaseItem extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'purchase_id', 'variant_id', 'quantity_ordered', 'quantity_received', 'unit_cost', 'landed_unit_cost',
        'discount', 'line_total', 'position',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'quantity_received' => 0,
        'discount' => '0.00',
        'position' => 0,
    ];

    public function quantityOutstanding(): int
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
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
            'purchase_id' => 'integer',
            'variant_id' => 'integer',
            'quantity_ordered' => 'integer',
            'quantity_received' => 'integer',
            'unit_cost' => 'decimal:4',
            'landed_unit_cost' => 'decimal:4',
            'discount' => MoneyCast::class,
            'line_total' => MoneyCast::class,
            'position' => 'integer',
        ];
    }
}
