<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\AttributeType;
use Database\Factories\ProductAttributeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A product attribute such as Color or Size (DATABASE-DESIGN §3.1). Variant-defining
 * attributes combine into a variable product's variants; filterable ones feed the
 * storefront facets.
 *
 * @property int $id
 * @property string $name
 * @property string|null $name_bn
 * @property string $slug
 * @property AttributeType $type
 * @property bool $is_variant_defining
 * @property bool $is_filterable
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ProductAttribute extends Model
{
    /** @use HasFactory<ProductAttributeFactory> */
    use HasFactory;

    public const int SLUG_MAX = 64;

    /** @var list<string> */
    protected $fillable = ['name', 'name_bn', 'slug', 'type', 'is_variant_defining', 'is_filterable', 'position'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'select',
        'is_variant_defining' => true,
        'is_filterable' => true,
        'position' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AttributeType::class,
            'is_variant_defining' => 'boolean',
            'is_filterable' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return HasMany<ProductAttributeValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class, 'attribute_id')->orderBy('position')->orderBy('id');
    }

    protected static function newFactory(): ProductAttributeFactory
    {
        return ProductAttributeFactory::new();
    }
}
