<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\DTOs\ProductData;
use App\Domain\Catalog\DTOs\VariantData;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductVariant;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The product invariants that hold whoever writes (admin API, import, seeder) —
 * checked before anything is written, all errors at once. Field keys follow the API
 * payload (`variants.2.compare_at_price`) so the admin form can place each message.
 *
 * Shape (types, lengths) is the FormRequest's job; this is the business rules:
 * category membership, the matrix definition, money signs, compare-at above price,
 * SKU and barcode uniqueness. Rules that need the written matrix (upsell price,
 * "at least one active variant") are checked by the writer afterwards.
 */
final class ProductDocumentValidator
{
    /** A matrix larger than this is almost certainly a mistake (and a slow write). */
    public const int MAX_VARIANTS = 100;

    /** @var array<string, list<string>> */
    private array $errors = [];

    /**
     * @return Collection<int, array{attribute: ProductAttribute, values: list<ProductAttributeValue>}> the selected options, attribute order
     *
     * @throws ValidationException
     */
    public function validate(ProductData $data, ?Product $existing): Collection
    {
        $this->errors = [];

        $this->checkClassification($data, $existing);
        $options = $this->checkOptions($data);
        $this->checkRows($data, $options, $existing);

        if ($this->errors !== []) {
            throw ValidationException::withMessages($this->errors);
        }

        return $options;
    }

    private function checkClassification(ProductData $data, ?Product $existing): void
    {
        if ($existing !== null && $existing->type !== $data->type) {
            $this->fail('type', 'The product type cannot change after creation.');
        }

        if ($data->ageMinMonths !== null && $data->ageMaxMonths !== null && $data->ageMinMonths > $data->ageMaxMonths) {
            $this->fail('age_max_months', 'The maximum age must not be below the minimum age.');
        }

        if ($data->brandId !== null && ! Brand::query()->whereKey($data->brandId)->exists()) {
            $this->fail('brand_id', 'The selected brand does not exist.');
        }

        if ($data->categoryIds === []) {
            $this->fail('category_ids', 'A product needs at least one category.');
        }

        if (! in_array($data->primaryCategoryId, $data->categoryIds, true)) {
            $this->fail('primary_category_id', 'The primary category must be one of the product\'s categories.');
        }

        $found = Category::query()->whereIn('id', $data->categoryIds)->pluck('id')->all();

        if (count(array_diff(array_unique($data->categoryIds), $found)) > 0) {
            $this->fail('category_ids', 'One or more selected categories do not exist.');
        }
    }

    /**
     * @return Collection<int, array{attribute: ProductAttribute, values: list<ProductAttributeValue>}>
     */
    private function checkOptions(ProductData $data): Collection
    {
        /** @var Collection<int, array{attribute: ProductAttribute, values: list<ProductAttributeValue>}> $none */
        $none = collect();

        if ($data->type === ProductType::Simple) {
            if ($data->options !== []) {
                $this->fail('options', 'A simple product has no variant options.');
            }

            return $none;
        }

        if ($data->options === []) {
            $this->fail('options', 'A variable product needs at least one variant-defining attribute.');

            return $none;
        }

        $attributeIds = array_map(static fn ($o): int => $o->attributeId, $data->options);
        $attributes = ProductAttribute::query()->whereIn('id', $attributeIds)->get()->keyBy('id');
        $valueIds = array_merge(...array_map(static fn ($o): array => $o->valueIds, $data->options));
        $values = ProductAttributeValue::query()->whereIn('id', $valueIds)->get()->keyBy('id');

        $seen = [];
        /** @var list<array{attribute: ProductAttribute, values: list<ProductAttributeValue>}> $options */
        $options = [];
        $combinations = 1;

        foreach ($data->options as $i => $option) {
            $attribute = $attributes->get($option->attributeId);

            if ($attribute === null) {
                $this->fail("options.{$i}.attribute_id", 'The selected attribute does not exist.');

                continue;
            }

            if (! $attribute->is_variant_defining) {
                $this->fail("options.{$i}.attribute_id", "\"{$attribute->name}\" does not define variants.");
            }

            if (isset($seen[$attribute->id])) {
                $this->fail("options.{$i}.attribute_id", "\"{$attribute->name}\" is selected twice.");

                continue;
            }
            $seen[$attribute->id] = true;

            if ($option->valueIds === [] || count(array_unique($option->valueIds)) !== count($option->valueIds)) {
                $this->fail("options.{$i}.value_ids", 'Select one or more distinct values.');

                continue;
            }

            $selected = [];
            foreach ($option->valueIds as $valueId) {
                $value = $values->get($valueId);

                if ($value === null || $value->attribute_id !== $attribute->id) {
                    $this->fail("options.{$i}.value_ids", "A selected value does not belong to \"{$attribute->name}\".");

                    continue 2;
                }

                $selected[] = $value;
            }

            usort($selected, static fn (ProductAttributeValue $a, ProductAttributeValue $b): int => [$a->position, $a->id] <=> [$b->position, $b->id]);
            $options[] = ['attribute' => $attribute, 'values' => $selected];
            $combinations *= count($selected);
        }

        if ($combinations > self::MAX_VARIANTS) {
            $this->fail('options', 'The selected values make '.$combinations.' variants; the limit is '.self::MAX_VARIANTS.'.');
        }

        usort($options, static fn (array $a, array $b): int => [$a['attribute']->position, $a['attribute']->id] <=> [$b['attribute']->position, $b['attribute']->id]);

        return collect($options);
    }

    /**
     * @param  Collection<int, array{attribute: ProductAttribute, values: list<ProductAttributeValue>}>  $options
     */
    private function checkRows(ProductData $data, Collection $options, ?Product $existing): void
    {
        if ($data->type === ProductType::Simple && count($data->variants) > 1) {
            $this->fail('variants', 'A simple product has exactly one variant.');
        }

        $defaults = $data->variantDefaults;
        if ($defaults !== null) {
            $this->checkMoney('variant_defaults', $defaults->price, $defaults->compareAtPrice, $defaults->costPrice, $defaults->wholesalePrice);
        }

        $allowed = [];
        foreach ($options as $option) {
            foreach ($option['values'] as $value) {
                $allowed[$value->id] = $option['attribute']->id;
            }
        }

        $ownIds = $existing?->variants()->pluck('id')->all() ?? [];
        $combos = [];
        $skus = [];
        $barcodes = [];

        foreach ($data->variants as $i => $row) {
            $this->checkMoney("variants.{$i}", $row->price, $row->compareAtPrice, $row->costPrice, $row->wholesalePrice);

            if ($row->weightGrams !== null && $row->weightGrams < 0) {
                $this->fail("variants.{$i}.weight_grams", 'The weight cannot be negative.');
            }

            if ($data->type === ProductType::Variable) {
                $this->checkCombination($i, $row, $allowed, count($options), $combos);
            }

            if ($row->sku !== null) {
                $key = strtoupper($row->sku);
                if (isset($skus[$key])) {
                    $this->fail("variants.{$i}.sku", 'This SKU is used by another row.');
                }
                $skus[$key] = true;

                if ($this->usedElsewhere('sku', $row->sku, $ownIds)) {
                    $this->fail("variants.{$i}.sku", 'This SKU is already in use.');
                }
            }

            if ($row->barcode !== null) {
                if (isset($barcodes[$row->barcode])) {
                    $this->fail("variants.{$i}.barcode", 'This barcode is used by another row.');
                }
                $barcodes[$row->barcode] = true;

                if ($this->usedElsewhere('barcode', $row->barcode, $ownIds)) {
                    $this->fail("variants.{$i}.barcode", 'This barcode is already in use.');
                }
            }
        }
    }

    /**
     * A row's attribute values must name exactly one selected value per selected attribute.
     *
     * @param  array<int, int>  $allowed  value id → attribute id
     * @param  array<string, true>  $combos
     */
    private function checkCombination(int $i, VariantData $row, array $allowed, int $attributeCount, array &$combos): void
    {
        $attributesSeen = [];

        foreach ($row->attributeValueIds as $valueId) {
            if (! isset($allowed[$valueId])) {
                $this->fail("variants.{$i}.attribute_value_ids", 'This row names a value that is not selected in the options.');

                return;
            }
            $attributesSeen[$allowed[$valueId]] = true;
        }

        if (count($row->attributeValueIds) !== $attributeCount || count($attributesSeen) !== $attributeCount) {
            $this->fail("variants.{$i}.attribute_value_ids", 'Give exactly one value for each selected attribute.');

            return;
        }

        $key = VariantMatrix::key($row->attributeValueIds);
        if (isset($combos[$key])) {
            $this->fail("variants.{$i}.attribute_value_ids", 'Two rows describe the same combination.');
        }
        $combos[$key] = true;
    }

    /**
     * Whether another product's variant already holds the value. Within the product a
     * row may keep its own SKU; a clash between two of its own variants is left to the
     * UNIQUE index, which the Action turns into a validation error.
     *
     * @param  list<int>  $ownIds
     */
    private function usedElsewhere(string $column, string $value, array $ownIds): bool
    {
        return ProductVariant::query()
            ->where($column, $value)
            ->when($ownIds !== [], static fn ($q) => $q->whereNotIn('id', $ownIds))
            ->exists();
    }

    private function checkMoney(string $prefix, ?Money $price, ?Money $compareAt, ?Money $cost, ?Money $wholesale): void
    {
        foreach (['price' => $price, 'compare_at_price' => $compareAt, 'cost_price' => $cost, 'wholesale_price' => $wholesale] as $field => $money) {
            if ($money !== null && $money->isNegative()) {
                $this->fail("{$prefix}.{$field}", 'An amount cannot be negative.');
            }
        }

        if ($price !== null && $compareAt !== null && ! $compareAt->greaterThan($price)) {
            $this->fail("{$prefix}.compare_at_price", 'The compare-at price must be higher than the price.');
        }
    }

    private function fail(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }
}
