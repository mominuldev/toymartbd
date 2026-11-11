<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A product image (DATABASE-DESIGN §3.1). `path` may be null until the media
 * pipeline (P4-CAT-07) exists; consumers render a placeholder for it.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $variant_id
 * @property string|null $path
 * @property string|null $alt_text
 * @property string|null $alt_text_bn
 * @property int $position
 * @property bool $is_primary
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 */
final class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['product_id', 'variant_id', 'path', 'alt_text', 'alt_text_bn', 'position', 'is_primary'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'position' => 0,
        'is_primary' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'variant_id' => 'integer',
            'position' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    protected static function newFactory(): ProductImageFactory
    {
        return ProductImageFactory::new();
    }
}
