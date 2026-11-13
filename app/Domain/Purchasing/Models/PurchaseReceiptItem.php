<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What one receipt brought in for one PO line (as built, P5). Never changed.
 *
 * @property int $id
 * @property int $receipt_id
 * @property int $purchase_item_id
 * @property int $variant_id
 * @property int $quantity
 * @property string $unit_cost
 * @property Money $line_value
 * @property Money $allocated_cost
 * @property string $landed_unit_cost
 * @property string $avg_cost_before
 * @property string $avg_cost_after
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PurchaseReceiptItem extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'receipt_id', 'purchase_item_id', 'variant_id', 'quantity', 'unit_cost', 'line_value', 'allocated_cost',
        'landed_unit_cost', 'avg_cost_before', 'avg_cost_after',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'receipt_id' => 'integer',
            'purchase_item_id' => 'integer',
            'variant_id' => 'integer',
            'quantity' => 'integer',
            'unit_cost' => 'decimal:4',
            'line_value' => MoneyCast::class,
            'allocated_cost' => MoneyCast::class,
            'landed_unit_cost' => 'decimal:4',
            'avg_cost_before' => 'decimal:4',
            'avg_cost_after' => 'decimal:4',
        ];
    }
}
