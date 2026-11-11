<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Domain\Catalog\DTOs\ProductData;
use App\Domain\Catalog\DTOs\ProductWriteContext;
use App\Domain\Catalog\DTOs\RelationData;
use App\Domain\Catalog\DTOs\VariantData;
use App\Domain\Catalog\DTOs\VariantDefaultsData;
use App\Domain\Catalog\DTOs\VariantOptionData;
use App\Domain\Catalog\Enums\Gender;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Enums\RelationType;
use App\Domain\Catalog\Enums\VariantStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Http\Requests\Admin\Brand\StoreBrandRequest;
use App\Http\Requests\ApiFormRequest;
use App\Support\Money;
use App\Support\Slug;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * The product document shared by `POST /products` and `PUT /products/{product}`:
 * product fields, categories, the variant matrix and curated relations, in one
 * payload. This request checks shape; the business rules (category membership,
 * matrix definition, compare-at above price, SKU/barcode uniqueness, upsell price)
 * live in the domain and surface as the same `validation-error` problem.
 *
 * Money is a decimal string ("1250.00", "1250", "99.5"), never a JSON number, so no
 * client or parser turns it into a float (rule 6).
 */
abstract class ProductDocumentRequest extends ApiFormRequest
{
    /** Non-negative, ≤ 12 integer digits, ≤ 2 decimals: DECIMAL(14,2). */
    public const string MONEY_PATTERN = '/^\d{1,12}(\.\d{1,2})?$/';

    /** Letters, digits and . _ - / ; starts with a letter or digit. */
    public const string SKU_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/';

    public const string BARCODE_PATTERN = '/^[A-Za-z0-9-]+$/';

    abstract protected function product(): ?Product;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $money = ['nullable', 'string', 'regex:'.self::MONEY_PATTERN];

        return [
            'name' => ['required', 'string', 'max:160'],
            'name_bn' => ['nullable', 'string', 'max:160'],
            'slug' => [
                'nullable', 'string', 'max:'.Product::SLUG_MAX, 'regex:'.StoreBrandRequest::SLUG_PATTERN,
                Rule::unique('products', 'slug')->ignore($this->product()?->id),
            ],
            'type' => ['required', Rule::enum(ProductType::class)],
            'status' => ['required', Rule::enum(ProductStatus::class)],
            'brand_id' => ['nullable', 'integer'],
            'primary_category_id' => ['required', 'integer'],
            'category_ids' => ['required', 'array', 'min:1', 'max:20'],
            'category_ids.*' => ['integer', 'distinct'],
            'short_description' => ['nullable', 'string', 'max:1000'],
            'short_description_bn' => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:50000'],
            'description_bn' => ['nullable', 'string', 'max:50000'],
            /**
             * Label → value pairs ("Material" → "ABS plastic"), a JSON object.
             *
             * @var array<string, string|null>|null
             */
            'specifications' => ['nullable', 'array', 'max:50'],
            'specifications.*' => ['nullable', 'string', 'max:500'],
            'age_min_months' => ['nullable', 'integer', 'min:0', 'max:216'],
            'age_max_months' => ['nullable', 'integer', 'min:0', 'max:216'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'is_educational' => ['sometimes', 'boolean'],
            'safety_note' => ['nullable', 'string', 'max:500'],
            'safety_note_bn' => ['nullable', 'string', 'max:500'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'is_featured' => ['sometimes', 'boolean'],
            'published_at' => ['nullable', 'date'],

            'options' => ['sometimes', 'array', 'max:3'],
            'options.*.attribute_id' => ['required', 'integer'],
            'options.*.value_ids' => ['required', 'array', 'min:1', 'max:30'],
            'options.*.value_ids.*' => ['integer'],

            'variant_defaults' => ['sometimes', 'nullable', 'array'],
            'variant_defaults.price' => $money,
            'variant_defaults.compare_at_price' => $money,
            'variant_defaults.cost_price' => $money,
            'variant_defaults.wholesale_price' => $money,
            'variant_defaults.weight_grams' => ['nullable', 'integer', 'min:0', 'max:1000000'],

            'variants' => ['sometimes', 'array', 'max:100'],
            'variants.*.attribute_value_ids' => ['sometimes', 'array', 'max:3'],
            'variants.*.attribute_value_ids.*' => ['integer'],
            'variants.*.sku' => ['nullable', 'string', 'max:'.ProductVariant::SKU_MAX, 'regex:'.self::SKU_PATTERN],
            'variants.*.barcode' => ['nullable', 'string', 'max:'.ProductVariant::BARCODE_MAX, 'regex:'.self::BARCODE_PATTERN],
            'variants.*.price' => $money,
            'variants.*.compare_at_price' => $money,
            'variants.*.cost_price' => $money,
            'variants.*.wholesale_price' => $money,
            'variants.*.weight_grams' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'variants.*.status' => ['nullable', Rule::enum(VariantStatus::class)],

            'relations' => ['sometimes', 'array', 'max:50'],
            'relations.*.related_product_id' => ['required', 'integer'],
            'relations.*.type' => ['required', Rule::enum(RelationType::class)],
            'relations.*.position' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'relations.*.reason_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $data = $validator->getData();

            if ($validator->errors()->hasAny(['name', 'name_bn', 'slug']) || $this->product() !== null || (is_string($data['slug'] ?? null) && $data['slug'] !== '')) {
                return;
            }

            try {
                Slug::forNames(is_string($data['name'] ?? null) ? $data['name'] : null, is_string($data['name_bn'] ?? null) ? $data['name_bn'] : null);
            } catch (InvalidArgumentException) {
                $validator->errors()->add('slug', 'A URL slug cannot be generated from this name; supply a slug.');
            }
        }];
    }

    public function toData(): ProductData
    {
        /** @var array<string, mixed> $v */
        $v = $this->validated();

        /** @var list<int> $categoryIds */
        $categoryIds = array_values(array_map(intval(...), (array) $v['category_ids']));

        return new ProductData(
            name: (string) $v['name'],
            type: ProductType::from((string) $v['type']),
            primaryCategoryId: (int) $v['primary_category_id'],
            categoryIds: $categoryIds,
            nameBn: self::text($v['name_bn'] ?? null),
            slug: self::text($v['slug'] ?? null),
            brandId: isset($v['brand_id']) ? (int) $v['brand_id'] : null,
            shortDescription: self::text($v['short_description'] ?? null),
            shortDescriptionBn: self::text($v['short_description_bn'] ?? null),
            description: self::text($v['description'] ?? null),
            descriptionBn: self::text($v['description_bn'] ?? null),
            specifications: is_array($v['specifications'] ?? null) && $v['specifications'] !== [] ? $v['specifications'] : null,
            ageMinMonths: isset($v['age_min_months']) ? (int) $v['age_min_months'] : null,
            ageMaxMonths: isset($v['age_max_months']) ? (int) $v['age_max_months'] : null,
            gender: isset($v['gender']) ? Gender::from((string) $v['gender']) : null,
            isEducational: (bool) ($v['is_educational'] ?? false),
            safetyNote: self::text($v['safety_note'] ?? null),
            safetyNoteBn: self::text($v['safety_note_bn'] ?? null),
            warrantyMonths: isset($v['warranty_months']) ? (int) $v['warranty_months'] : null,
            status: ProductStatus::from((string) $v['status']),
            isFeatured: (bool) ($v['is_featured'] ?? false),
            publishedAt: isset($v['published_at']) ? Carbon::parse((string) $v['published_at']) : null,
            options: array_values(array_map(
                static fn (array $o): VariantOptionData => new VariantOptionData((int) $o['attribute_id'], array_values(array_map(intval(...), (array) $o['value_ids']))),
                (array) ($v['options'] ?? []),
            )),
            variants: array_values(array_map(self::variant(...), (array) ($v['variants'] ?? []))),
            variantDefaults: is_array($v['variant_defaults'] ?? null) ? new VariantDefaultsData(
                price: self::money($v['variant_defaults']['price'] ?? null),
                compareAtPrice: self::money($v['variant_defaults']['compare_at_price'] ?? null),
                costPrice: self::money($v['variant_defaults']['cost_price'] ?? null),
                wholesalePrice: self::money($v['variant_defaults']['wholesale_price'] ?? null),
                weightGrams: isset($v['variant_defaults']['weight_grams']) ? (int) $v['variant_defaults']['weight_grams'] : null,
            ) : null,
            relations: array_key_exists('relations', $v) ? array_values(array_map(
                static fn (array $r): RelationData => new RelationData(
                    relatedProductId: (int) $r['related_product_id'],
                    type: RelationType::from((string) $r['type']),
                    position: (int) ($r['position'] ?? 0),
                    reasonNote: self::text($r['reason_note'] ?? null),
                ),
                (array) $v['relations'],
            )) : null,
        );
    }

    /** The caller's field-level abilities, from the ProductPolicy. */
    public function writeContext(): ProductWriteContext
    {
        $user = $this->user();
        $product = $this->product();

        return new ProductWriteContext(
            actorId: $user?->id,
            canChangePrices: $product === null || ($user?->can('updatePrice', $product) ?? false),
            canSetCost: $user?->can('viewCost', Product::class) ?? false,
        );
    }

    /** @param  array<string, mixed>  $row */
    private static function variant(array $row): VariantData
    {
        return new VariantData(
            attributeValueIds: array_values(array_map(intval(...), (array) ($row['attribute_value_ids'] ?? []))),
            sku: self::text($row['sku'] ?? null),
            barcode: self::text($row['barcode'] ?? null),
            price: self::money($row['price'] ?? null),
            compareAtPrice: self::money($row['compare_at_price'] ?? null),
            costPrice: self::money($row['cost_price'] ?? null),
            wholesalePrice: self::money($row['wholesale_price'] ?? null),
            weightGrams: isset($row['weight_grams']) ? (int) $row['weight_grams'] : null,
            status: isset($row['status']) ? VariantStatus::from((string) $row['status']) : null,
        );
    }

    private static function money(mixed $value): ?Money
    {
        return is_string($value) && $value !== '' ? Money::of($value) : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
