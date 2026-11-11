<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\RelationType;
use Database\Factories\ProductRelationFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A recommendation pair (DATABASE-DESIGN §3.1). `is_auto` rows come from a relation
 * rule (P2) and are regenerated freely; hand-curated rows (`is_auto = false`) are
 * only ever changed by a human through UpdateProduct.
 *
 * @property int $id
 * @property int $product_id
 * @property int $related_product_id
 * @property RelationType $type
 * @property int $position
 * @property string|null $reason_note
 * @property bool $is_auto
 * @property int|null $rule_id
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 * @property-read Product $relatedProduct
 */
final class ProductRelation extends Model
{
    /** @use HasFactory<ProductRelationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['product_id', 'related_product_id', 'type', 'position', 'reason_note', 'is_auto', 'rule_id', 'created_by'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'position' => 0,
        'is_auto' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'related_product_id' => 'integer',
            'type' => RelationType::class,
            'position' => 'integer',
            'is_auto' => 'boolean',
            'rule_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function relatedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'related_product_id')->withTrashed();
    }

    /**
     * Hand-curated rows only.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function curated(Builder $query): void
    {
        $query->where('is_auto', false);
    }

    protected static function newFactory(): ProductRelationFactory
    {
        return ProductRelationFactory::new();
    }
}
