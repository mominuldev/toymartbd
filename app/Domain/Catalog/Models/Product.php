<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Contracts\ProductSearchDocuments;
use App\Domain\Catalog\Enums\Gender;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Enums\VariantStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as BaseCollection;
use Laravel\Scout\Searchable;

/**
 * A product (DATABASE-DESIGN §3.1). Holds content and classification only — SKU,
 * barcode, prices and stock live on its variants (§1.1). Written only through
 * CreateProduct / UpdateProduct / ArchiveProduct.
 *
 * The slug is ASCII and shared by both locales (rule 37).
 *
 * A change to a published slug is recorded in `slug_history` by the Seo domain's
 * SaveProductWithSeo (the 301 middleware that serves it is Phase 7).
 *
 * @property int $id
 * @property string $name
 * @property string|null $name_bn
 * @property string $slug
 * @property ProductType $type
 * @property int|null $brand_id
 * @property int $primary_category_id
 * @property string|null $short_description
 * @property string|null $short_description_bn
 * @property string|null $description
 * @property string|null $description_bn
 * @property array<string, mixed>|null $specifications
 * @property int|null $age_min_months
 * @property int|null $age_max_months
 * @property Gender|null $gender
 * @property bool $is_educational
 * @property string|null $safety_note
 * @property string|null $safety_note_bn
 * @property int|null $warranty_months
 * @property ProductStatus $status
 * @property bool $is_featured
 * @property bool $channel_online_store
 * @property bool $channel_pos
 * @property bool $channel_social
 * @property bool $channel_google_merchant
 * @property string $vat_rate percent, decimal string ("0.00", "15.00")
 * @property string|null $bsti_certificate_no
 * @property Carbon|null $bsti_expires_on
 * @property string|null $bsti_certificate_path private originals-disk key of the certificate PDF
 * @property string|null $bsti_certificate_name
 * @property int|null $bsti_certificate_size bytes
 * @property string|null $rating_avg
 * @property int $rating_count
 * @property int $view_count
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Brand|null $brand
 * @property-read Category $primaryCategory
 * @property-read Collection<int, Category> $categories
 * @property-read Collection<int, ProductVariant> $variants
 * @property-read ProductVariant|null $defaultVariant
 * @property-read Collection<int, ProductImage> $images
 * @property-read Collection<int, ProductRelation> $productRelations
 */
final class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use Searchable;
    use SoftDeletes;

    /** Generated slugs leave room for a "-NNN" suffix; "product:" + 92 = the 100-char tag limit. */
    public const int SLUG_BASE_MAX = 88;

    public const int SLUG_MAX = 92;

    /** @var list<string> */
    protected $fillable = [
        'name', 'name_bn', 'slug', 'type', 'brand_id', 'primary_category_id',
        'short_description', 'short_description_bn', 'description', 'description_bn', 'specifications',
        'age_min_months', 'age_max_months', 'gender', 'is_educational', 'safety_note', 'safety_note_bn',
        'warranty_months', 'status', 'is_featured', 'published_at',
        'channel_online_store', 'channel_pos', 'channel_social', 'channel_google_merchant',
        'vat_rate', 'bsti_certificate_no', 'bsti_expires_on',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'is_educational' => false,
        'is_featured' => false,
        'channel_online_store' => true,
        'channel_pos' => true,
        'channel_social' => false,
        'channel_google_merchant' => false,
        'vat_rate' => '0.00',
        'rating_count' => 0,
        'view_count' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'status' => ProductStatus::class,
            'gender' => Gender::class,
            'specifications' => 'array',
            'brand_id' => 'integer',
            'primary_category_id' => 'integer',
            'age_min_months' => 'integer',
            'age_max_months' => 'integer',
            'warranty_months' => 'integer',
            'is_educational' => 'boolean',
            'is_featured' => 'boolean',
            'rating_count' => 'integer',
            'view_count' => 'integer',
            'published_at' => 'datetime',
            'channel_online_store' => 'boolean',
            'channel_pos' => 'boolean',
            'channel_social' => 'boolean',
            'channel_google_merchant' => 'boolean',
            'vat_rate' => 'decimal:2',
            'bsti_expires_on' => 'date',
            'bsti_certificate_size' => 'integer',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'primary_category_id');
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product');
    }

    /**
     * Every variant, archived included, in matrix order.
     *
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<ProductVariant, $this> */
    public function activeVariants(): HasMany
    {
        return $this->variants()->where('status', VariantStatus::Active->value);
    }

    /** @return HasOne<ProductVariant, $this> */
    public function defaultVariant(): HasOne
    {
        return $this->hasOne(ProductVariant::class)->where('is_default', true);
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Outgoing recommendation pairs (this product → recommended products). Not
     * `relations()`: Eloquent owns `$relations`.
     *
     * @return HasMany<ProductRelation, $this>
     */
    public function productRelations(): HasMany
    {
        return $this->hasMany(ProductRelation::class)->orderBy('type')->orderBy('position')->orderBy('id');
    }

    public function isActive(): bool
    {
        return $this->status === ProductStatus::Active;
    }

    /** Live on the storefront now: active with `published_at` reached (the `published` scope, for one row). */
    public function isLive(): bool
    {
        return $this->isActive() && $this->published_at !== null && $this->published_at->lessThanOrEqualTo(now());
    }

    /**
     * Status `active`, whether or not `published_at` has passed.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', ProductStatus::Active->value);
    }

    /**
     * Live on the storefront: active and `published_at` reached.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', ProductStatus::Active->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    // ----- Search (P4-CAT-14) — docs/api/search.md ------------------------------------

    /**
     * Scout's model observer is OFF for products: it would index synchronously on `saved`,
     * inside the Action's transaction, before the variants are written — and an HTTP call
     * inside a transaction breaks CLAUDE.md rule 4. Index writes come from domain events
     * instead (App\Domain\Search\Jobs\SyncProductSearch on the `index` queue, after commit).
     */
    protected static function booted(): void
    {
        self::disableSearchSyncing();
    }

    public function searchableAs(): string
    {
        return config('scout.prefix').config('search.index', 'products');
    }

    public function shouldBeSearchable(): bool
    {
        return app(ProductSearchDocuments::class)->isSearchable($this);
    }

    /**
     * @param  BaseCollection<int, self>  $models
     * @return BaseCollection<int, self>
     */
    public function makeSearchableUsing(BaseCollection $models): BaseCollection
    {
        app(ProductSearchDocuments::class)->prepare($models);

        return $models;
    }

    /** @return array<string, mixed> */
    public function toSearchableArray(): array
    {
        return app(ProductSearchDocuments::class)->build($this);
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
