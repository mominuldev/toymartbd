<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Models;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Ordering\Exceptions\OrderViolation;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One order line with everything snapshotted (DATABASE-DESIGN §3.4).
 *
 * `cost_price_snapshot` is DECIMAL(14,4), written once at creation and never changed
 * (rule 7): an update that touches it throws. It is read as a decimal string — Money is
 * two-place, and COGS rounds to the poisha only when multiplied out ({@see cogs()}).
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $variant_id
 * @property int|null $product_id
 * @property string $product_name
 * @property string|null $product_name_bn
 * @property string|null $variant_name
 * @property string $sku
 * @property string|null $image_path
 * @property array<string, string>|null $variant_attributes
 * @property Money $unit_price
 * @property int $quantity
 * @property Money $discount
 * @property Money $tax
 * @property Money $line_total
 * @property string $cost_price_snapshot
 * @property int $quantity_shipped
 * @property int $quantity_returned
 * @property int $quantity_refunded
 * @property string|null $recommendation_source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 * @property-read ProductVariant|null $variant
 */
final class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static function (self $item): void {
            if ($item->isDirty('cost_price_snapshot')) {
                throw OrderViolation::costSnapshotImmutable();
            }
        });
        self::deleting(static fn (): never => throw OrderViolation::neverDeleted());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'variant_id' => 'integer',
            'product_id' => 'integer',
            'variant_attributes' => 'array',
            'unit_price' => MoneyCast::class,
            'quantity' => 'integer',
            'discount' => MoneyCast::class,
            'tax' => MoneyCast::class,
            'line_total' => MoneyCast::class,
            'cost_price_snapshot' => 'decimal:4',
            'quantity_shipped' => 'integer',
            'quantity_returned' => 'integer',
            'quantity_refunded' => 'integer',
        ];
    }

    /** Snapshot cost × quantity, rounded to the poisha. */
    public function cogs(?int $quantity = null): Money
    {
        return Money::of(bcmul($this->cost_price_snapshot, (string) ($quantity ?? $this->quantity), 4));
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    protected static function newFactory(): OrderItemFactory
    {
        return OrderItemFactory::new();
    }
}
