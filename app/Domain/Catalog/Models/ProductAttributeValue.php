<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use Database\Factories\ProductAttributeValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One value of a product attribute, e.g. "Red" (#E53935) under Color. Its slug is
 * unique within the attribute.
 *
 * @property int $id
 * @property int $attribute_id
 * @property string $value
 * @property string|null $value_bn
 * @property string $slug
 * @property string|null $color_hex
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProductAttribute $attribute
 */
final class ProductAttributeValue extends Model
{
    /** @use HasFactory<ProductAttributeValueFactory> */
    use HasFactory;

    public const int SLUG_MAX = 64;

    /** @var list<string> */
    protected $fillable = ['attribute_id', 'value', 'value_bn', 'slug', 'color_hex', 'position'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'position' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attribute_id' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<ProductAttribute, $this> */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'attribute_id');
    }

    /**
     * Whether any variant uses this value (`variant_attribute_values`, owned by the
     * product/variant schema). A referenced value must never be deleted: the variant
     * would lose the combination that names it.
     */
    public function isUsedByVariants(): bool
    {
        return DB::table('variant_attribute_values')->where('attribute_value_id', $this->id)->exists();
    }

    protected static function newFactory(): ProductAttributeValueFactory
    {
        return ProductAttributeValueFactory::new();
    }
}
