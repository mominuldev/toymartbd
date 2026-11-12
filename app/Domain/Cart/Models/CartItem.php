<?php

declare(strict_types=1);

namespace App\Domain\Cart\Models;

use App\Domain\Catalog\Models\ProductVariant;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One cart line — one per variant per cart (unique key), so adding the same variant
 * again or merging a guest cart raises the quantity instead of duplicating the line.
 * `unit_price` is the price when first added; `line_total` caches the last priced
 * line total after discounts.
 *
 * @property int $id
 * @property int $cart_id
 * @property int $variant_id
 * @property int $quantity
 * @property Money $unit_price
 * @property Money $line_total
 * @property Carbon $added_at
 * @property-read Cart $cart
 * @property-read ProductVariant $variant
 */
final class CartItem extends Model
{
    /** @var list<string> */
    protected $fillable = ['cart_id', 'variant_id', 'quantity', 'unit_price', 'line_total', 'added_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cart_id' => 'integer',
            'variant_id' => 'integer',
            'quantity' => 'integer',
            'unit_price' => MoneyCast::class,
            'line_total' => MoneyCast::class,
            'added_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
