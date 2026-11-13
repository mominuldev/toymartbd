<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Models;

use App\Domain\Catalog\Models\ProductVariant;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One variant in a flash sale: its sale price and the allotment sold at that price.
 * `claimed` is changed only by PromotionUsages under this row's lock (P7).
 *
 * @property int $id
 * @property int $promotion_id
 * @property int $variant_id
 * @property Money $sale_price
 * @property int $allotment
 * @property int $claimed
 * @property int|null $per_order_limit
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Promotion $promotion
 * @property-read ProductVariant $variant
 */
final class FlashSaleItem extends Model
{
    /** @var list<string> */
    protected $fillable = ['promotion_id', 'variant_id', 'sale_price', 'allotment', 'per_order_limit'];

    /** @var array<string, mixed> */
    protected $attributes = ['claimed' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'promotion_id' => 'integer',
            'variant_id' => 'integer',
            'sale_price' => MoneyCast::class,
            'allotment' => 'integer',
            'claimed' => 'integer',
            'per_order_limit' => 'integer',
        ];
    }

    public function remaining(): int
    {
        return max(0, $this->allotment - $this->claimed);
    }

    /** @return BelongsTo<Promotion, $this> */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
