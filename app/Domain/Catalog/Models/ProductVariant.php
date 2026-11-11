<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\VariantStatus;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A sellable variant — the unit of inventory (DATABASE-DESIGN §1.1). Every money
 * column is DECIMAL(14,2) read through MoneyCast (rule 6). Archived, never deleted.
 *
 * @property int $id
 * @property int $product_id
 * @property string $sku
 * @property string|null $barcode
 * @property string|null $name
 * @property string|null $name_bn
 * @property Money $cost_price
 * @property Money $price
 * @property Money|null $compare_at_price
 * @property Money|null $wholesale_price
 * @property int $weight_grams
 * @property bool $is_default
 * @property int $position
 * @property VariantStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 * @property-read Collection<int, ProductAttributeValue> $attributeValues
 */
final class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    public const int SKU_MAX = 64;

    public const int BARCODE_MAX = 32;

    /** @var list<string> */
    protected $fillable = [
        'product_id', 'sku', 'barcode', 'name', 'name_bn', 'cost_price', 'price', 'compare_at_price',
        'wholesale_price', 'weight_grams', 'is_default', 'position', 'status',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
        'cost_price' => '0.00',
        'weight_grams' => 0,
        'is_default' => false,
        'position' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'cost_price' => MoneyCast::class,
            'price' => MoneyCast::class,
            'compare_at_price' => MoneyCast::class,
            'wholesale_price' => MoneyCast::class,
            'weight_grams' => 'integer',
            'is_default' => 'boolean',
            'position' => 'integer',
            'status' => VariantStatus::class,
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The value this variant carries for each variant-defining attribute
     * (`variant_attribute_values`, pivot also holds `attribute_id`).
     *
     * @return BelongsToMany<ProductAttributeValue, $this>
     */
    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductAttributeValue::class, 'variant_attribute_values', 'variant_id', 'attribute_value_id')
            ->withPivot('attribute_id');
    }

    public function isActive(): bool
    {
        return $this->status === VariantStatus::Active;
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', VariantStatus::Active->value);
    }

    protected static function newFactory(): ProductVariantFactory
    {
        return ProductVariantFactory::new();
    }
}
