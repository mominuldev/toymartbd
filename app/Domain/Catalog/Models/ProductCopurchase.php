<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use Database\Factories\ProductCopurchaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How many distinct non-cancelled orders in the last 180 days held both products (P10, PRD X-04).
 * A rebuildable projection — {@see \App\Domain\Catalog\Actions\RebuildProductCopurchases} replaces
 * every row nightly; both directions are stored, so read by `product_id` only. Frequently bought
 * together needs `orders_count >= config('catalog.fbt_min_orders')`.
 *
 * Composite key (`product_id`, `other_product_id`): treat rows as read-only.
 *
 * @property int $product_id
 * @property int $other_product_id
 * @property int $orders_count
 * @property Carbon $last_ordered_at
 * @property Carbon $computed_at
 * @property-read Product $product
 * @property-read Product $otherProduct
 */
final class ProductCopurchase extends Model
{
    /** @use HasFactory<ProductCopurchaseFactory> */
    use HasFactory;

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'product_id';

    /** @var list<string> */
    protected $fillable = ['product_id', 'other_product_id', 'orders_count', 'last_ordered_at', 'computed_at'];

    protected static function newFactory(): ProductCopurchaseFactory
    {
        return ProductCopurchaseFactory::new();
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function otherProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'other_product_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'other_product_id' => 'integer',
            'orders_count' => 'integer',
            'last_ordered_at' => 'datetime',
            'computed_at' => 'datetime',
        ];
    }
}
