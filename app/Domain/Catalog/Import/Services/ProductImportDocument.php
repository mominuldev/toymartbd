<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import\Services;

use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Import\Exceptions\RowRejected;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductVariant;

/**
 * Turns one spreadsheet row into the API product document that `POST /products` or
 * `PUT /products/{product}` accepts (P4-CAT-10), and maps the document's error paths
 * back to spreadsheet columns. It only translates — every rule (required fields,
 * price formats, category membership, compare-at above price, SKU uniqueness, the
 * field-level abilities) is checked by the product FormRequest and actions.
 *
 * - Upsert by SKU: a SKU that matches a variant updates that variant's product; an
 *   unknown SKU creates a **simple** product with that SKU. A variable product's
 *   matrix (options and combinations) is defined in the product form, so the import
 *   can update its variants but never create one.
 * - An update starts from the product's current document, so every column absent from
 *   the file, and everything the sheet has no column for (specifications, relations,
 *   SEO, sales channels, compliance), keeps its current value. A present but blank
 *   cell clears the field; `cost_price` and `vat_rate` blank keep the current value.
 * - `brand`, `primary_category` and `categories` are slugs (ASCII, shared by both
 *   locales, rule 37); `categories` is `|`-separated, and when absent on a new product
 *   it is the primary category alone.
 * - Booleans read 1/0, true/false, yes/no; a new product without a `status` is a draft.
 */
final class ProductImportDocument
{
    /** @var array<string, int|null> */
    private array $brands = [];

    /** @var array<string, int|null> */
    private array $categories = [];

    private const array TEXT = [
        'name', 'name_bn', 'short_description', 'short_description_bn', 'description', 'description_bn',
        'safety_note', 'safety_note_bn',
    ];

    private const array INTEGERS = ['age_min_months', 'age_max_months', 'warranty_months'];

    private const array BOOLEANS = ['is_educational', 'is_featured'];

    private const array MONEY = ['price', 'compare_at_price', 'cost_price', 'wholesale_price'];

    /**
     * @param  array<string, string|null>  $row  column → cell (only the file's columns)
     * @return array<string, mixed> the API document
     *
     * @throws RowRejected
     */
    public function build(array $row, ?ProductVariant $match): array
    {
        $product = $match?->product;
        $document = $product !== null ? $this->current($product) : ['type' => ProductType::Simple->value, 'status' => 'draft', 'variants' => [[]]];
        $index = $product !== null ? $this->variantIndex($product, $match) : 0;

        $problems = [];
        $has = static fn (string $column): bool => array_key_exists($column, $row);
        $cell = static fn (string $column): ?string => self::clean($row[$column] ?? null);

        if ($product === null && $has('type') && $cell('type') !== null && $cell('type') !== ProductType::Simple->value) {
            throw RowRejected::column('type', 'A new product can only be created as simple from a file. Create variable products, and their variants, in the product form.');
        }

        foreach (self::TEXT as $column) {
            if ($has($column)) {
                $document[$column] = $cell($column);
            }
        }

        if ($has('slug') && $cell('slug') !== null) {
            $document['slug'] = $cell('slug');
        }

        if ($has('status') && $cell('status') !== null) {
            $document['status'] = $cell('status');
        }

        foreach (self::INTEGERS as $column) {
            if ($has($column)) {
                $document[$column] = $cell($column);
            }
        }

        foreach (self::BOOLEANS as $column) {
            if ($has($column)) {
                $document[$column] = self::boolean($cell($column));
            }
        }

        if ($has('gender')) {
            $document['gender'] = $cell('gender') === null ? null : strtolower((string) $cell('gender'));
        }

        if ($has('vat_rate') && $cell('vat_rate') !== null) {
            $document['vat_rate'] = $cell('vat_rate');
        }

        if ($has('brand')) {
            $slug = $cell('brand');
            $id = $slug === null ? null : $this->brandId($slug);
            if ($slug !== null && $id === null) {
                $problems['brand'][] = "No brand has the slug \"{$slug}\".";
            }
            $document['brand_id'] = $id;
        }

        if ($has('primary_category')) {
            $slug = $cell('primary_category');
            $id = $slug === null ? null : $this->categoryId($slug);
            if ($slug !== null && $id === null) {
                $problems['primary_category'][] = "No category has the slug \"{$slug}\".";
            }
            $document['primary_category_id'] = $id;
        }

        if ($has('categories')) {
            $ids = [];
            foreach (array_filter(array_map('trim', explode(ProductSpreadsheet::LIST_SEPARATOR, (string) $cell('categories')))) as $slug) {
                $id = $this->categoryId($slug);
                if ($id === null) {
                    $problems['categories'][] = "No category has the slug \"{$slug}\".";
                } else {
                    $ids[] = $id;
                }
            }
            $document['category_ids'] = array_values(array_unique($ids));
        } elseif ($product === null && isset($document['primary_category_id'])) {
            $document['category_ids'] = [$document['primary_category_id']];
        }

        /** @var list<array<string, mixed>> $variants */
        $variants = $document['variants'];
        $variant = $variants[$index] ?? [];
        $variant['sku'] = $cell('sku');

        foreach (['barcode', 'weight_grams', ...self::MONEY] as $column) {
            if (! $has($column)) {
                continue;
            }
            $value = $cell($column);
            if ($value === null && in_array($column, ['cost_price'], true)) {
                unset($variant[$column]);

                continue;
            }
            $variant[$column] = $value;
        }

        if ($has('variant_status') && $cell('variant_status') !== null) {
            $variant['status'] = $cell('variant_status');
        }

        if ($has('opening_stock') && $cell('opening_stock') !== null) {
            $variant['opening_stock'] = $cell('opening_stock');
        }

        $variants[$index] = $variant;
        $document['variants'] = $variants;

        if ($problems !== []) {
            throw new RowRejected($problems);
        }

        return $document;
    }

    /**
     * The spreadsheet column an API error path belongs to (`variants.0.price` → `price`,
     * `brand_id` → `brand`); null when it belongs to the row as a whole.
     */
    public static function column(string $field): ?string
    {
        $parts = explode('.', $field);
        $head = $parts[0];

        if (in_array($head, ['variants', 'variant_defaults'], true)) {
            $tail = $parts[count($parts) - 1];

            return match (true) {
                $tail === 'status' => 'variant_status',
                $tail === 'attribute_value_ids', ctype_digit($tail), $tail === 'variants' => 'sku',
                default => $tail,
            };
        }

        return match ($head) {
            'brand_id' => 'brand',
            'primary_category_id' => 'primary_category',
            'category_ids' => 'categories',
            'options', 'relations', 'seo', 'sales_channels', 'compliance', 'specifications', 'acknowledge_slug_redirect' => null,
            default => in_array($head, ProductSpreadsheet::importableColumns(), true) ? $head : null,
        };
    }

    /**
     * The product's current document, in the API shape the product form PUTs back.
     *
     * @return array<string, mixed>
     */
    private function current(Product $product): array
    {
        $product->loadMissing(['categories', 'variants.attributeValues']);

        $options = [];
        foreach ($product->variants as $variant) {
            if (! $variant->isActive()) {
                continue;
            }
            foreach ($variant->attributeValues as $value) {
                /** @var ProductAttributeValue $value */
                $options[$value->attribute_id][$value->id] = true;
            }
        }

        $document = [
            'name' => $product->name,
            'name_bn' => $product->name_bn,
            'type' => $product->type->value,
            'status' => $product->status->value,
            'brand_id' => $product->brand_id,
            'primary_category_id' => $product->primary_category_id,
            'category_ids' => $product->categories->modelKeys(),
            'short_description' => $product->short_description,
            'short_description_bn' => $product->short_description_bn,
            'description' => $product->description,
            'description_bn' => $product->description_bn,
            'specifications' => $product->specifications,
            'age_min_months' => $product->age_min_months,
            'age_max_months' => $product->age_max_months,
            'gender' => $product->gender?->value,
            'is_educational' => $product->is_educational,
            'safety_note' => $product->safety_note,
            'safety_note_bn' => $product->safety_note_bn,
            'warranty_months' => $product->warranty_months,
            'is_featured' => $product->is_featured,
            'options' => array_map(
                static fn (int $attributeId, array $valueIds): array => ['attribute_id' => $attributeId, 'value_ids' => array_keys($valueIds)],
                array_keys($options),
                $options,
            ),
            'variants' => [],
        ];

        foreach ($this->variantsInOrder($product) as $variant) {
            $document['variants'][] = [
                'attribute_value_ids' => $variant->attributeValues->modelKeys(),
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'price' => $variant->price->toDecimal(),
                'compare_at_price' => $variant->compare_at_price?->toDecimal(),
                'wholesale_price' => $variant->wholesale_price?->toDecimal(),
                'weight_grams' => $variant->weight_grams,
                'status' => $variant->status->value,
            ];
        }

        return $document;
    }

    /** @return list<ProductVariant> */
    private function variantsInOrder(Product $product): array
    {
        $variants = $product->variants->sortBy([['position', 'asc'], ['id', 'asc']])->values();

        if ($product->type === ProductType::Simple) {
            $default = $variants->firstWhere('is_default', true) ?? $variants->first();

            return $default === null ? [] : [$default];
        }

        return $variants->all();
    }

    private function variantIndex(Product $product, ProductVariant $match): int
    {
        foreach ($this->variantsInOrder($product) as $i => $variant) {
            if ($variant->id === $match->id) {
                return $i;
            }
        }

        throw RowRejected::column('sku', 'This SKU belongs to a variant the product no longer uses; edit it in the product form.');
    }

    private function brandId(string $slug): ?int
    {
        if (! array_key_exists($slug, $this->brands)) {
            $id = Brand::query()->where('slug', $slug)->value('id');
            $this->brands[$slug] = is_numeric($id) ? (int) $id : null;
        }

        return $this->brands[$slug];
    }

    private function categoryId(string $slug): ?int
    {
        if (! array_key_exists($slug, $this->categories)) {
            $id = Category::query()->where('slug', $slug)->value('id');
            $this->categories[$slug] = is_numeric($id) ? (int) $id : null;
        }

        return $this->categories[$slug];
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** 1/0, true/false, yes/no → bool; blank → false; anything else is passed on for the FormRequest to reject. */
    private static function boolean(?string $value): bool|string
    {
        if ($value === null) {
            return false;
        }

        return match (strtolower($value)) {
            '1', 'true', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default => $value,
        };
    }
}
