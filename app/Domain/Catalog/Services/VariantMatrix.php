<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\DTOs\ProductData;
use App\Domain\Catalog\DTOs\VariantData;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Enums\VariantStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Writes a product's variants from its document (P4-CAT-03). No transaction of its
 * own — it runs inside CreateProduct / UpdateProduct.
 *
 * Simple product: exactly one variant, the default.
 *
 * Variable product: one variant per combination of the selected option values (the
 * cartesian product, in attribute then value order — 3 colours × 4 sizes = 12).
 * Reconciling against what exists:
 *   - a combination that already has a variant keeps it: same id, SKU and prices,
 *     unless its row changes them;
 *   - a new combination gets a new variant (generated SKU, `variantDefaults`);
 *   - a variant whose combination left the matrix (a value or attribute removed) is
 *     ARCHIVED, never deleted — stock and orders reference it;
 *   - re-adding a removed value brings its archived variants back to active.
 * Exactly one active variant is the default: the current one while it survives,
 * else the first active combination.
 *
 * Price and cost changes to an existing variant are written to `audit_sensitive`.
 */
final class VariantMatrix
{
    private bool $canChangePrices = true;

    public function __construct(private readonly VariantSkus $skus) {}

    /**
     * The identity of a combination: its sorted attribute value ids.
     *
     * @param  list<int>  $valueIds
     */
    public static function key(array $valueIds): string
    {
        sort($valueIds);

        return implode(',', $valueIds);
    }

    /**
     * @param  Collection<int, array{attribute: ProductAttribute, values: list<ProductAttributeValue>}>  $options
     * @param  bool  $canChangePrices  false: refuse (403) any selling-price change or new priced variant
     * @return bool whether any variant was created or changed
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function sync(Product $product, ProductData $data, Collection $options, ?int $actorId, bool $canChangePrices = true): bool
    {
        $this->canChangePrices = $canChangePrices;
        $this->skus->reserve(array_values(array_filter(array_map(static fn (VariantData $row): ?string => $row->sku, $data->variants))));

        $changed = $data->type === ProductType::Simple
            ? $this->syncSimple($product, $data, $actorId)
            : $this->syncVariable($product, $data, $options, $actorId);

        $product->unsetRelation('variants');

        if (! $product->variants()->where('status', VariantStatus::Active->value)->exists()) {
            throw ValidationException::withMessages(['variants' => 'A product needs at least one active variant.']);
        }

        return $changed;
    }

    private function syncSimple(Product $product, ProductData $data, ?int $actorId): bool
    {
        $row = $data->variants[0] ?? null;
        $variant = $product->variants()->reorder()->orderByDesc('is_default')->orderBy('id')->first();

        if ($variant === null) {
            $variant = $this->newVariant($product, $row, $data, 'variants.0', []);
            $variant->is_default = true;
            $variant->save();

            return true;
        }

        if ($row !== null) {
            $this->applyRow($variant, $row, 'variants.0');
        }
        $variant->is_default = true;

        return $this->save($variant, $actorId);
    }

    /**
     * @param  Collection<int, array{attribute: ProductAttribute, values: list<ProductAttributeValue>}>  $options
     */
    private function syncVariable(Product $product, ProductData $data, Collection $options, ?int $actorId): bool
    {
        $changed = false;
        $combinations = self::cartesian($options->pluck('values')->all());

        $rows = [];
        foreach ($data->variants as $i => $row) {
            $rows[self::key($row->attributeValueIds)] = [$i, $row];
        }

        /** @var EloquentCollection<int, ProductVariant> $existing */
        $existing = $product->variants()->with('attributeValues')->get();
        $byKey = $existing->keyBy(fn (ProductVariant $v): string => self::key($v->attributeValues->modelKeys()));

        // Values the live matrix used before this write: a combination with any value
        // outside it is newly (re-)added, so its archived variant is reactivated.
        $previousValues = $existing->filter(fn (ProductVariant $v): bool => $v->isActive())
            ->flatMap(fn (ProductVariant $v): array => $v->attributeValues->modelKeys())
            ->flip();

        $kept = [];
        $default = null;

        foreach ($combinations as $position => $values) {
            $valueIds = array_map(static fn (ProductAttributeValue $v): int => $v->id, $values);
            $key = self::key($valueIds);
            [$rowIndex, $row] = $rows[$key] ?? [null, null];
            $field = $rowIndex !== null ? "variants.{$rowIndex}" : 'variant_defaults';
            $variant = $byKey->get($key);

            if ($variant === null) {
                $variant = $this->newVariant($product, $row, $data, $field, $values);
                $variant->position = $position;
                $variant->save();
                foreach ($values as $value) {
                    $variant->attributeValues()->attach($value->id, ['attribute_id' => $value->attribute_id]);
                }
                $changed = true;
            } else {
                $readded = collect($valueIds)->contains(fn (int $id): bool => ! $previousValues->has($id));

                if ($row !== null) {
                    $this->applyRow($variant, $row, $field);
                }

                if ($row?->status === null && $readded && ! $variant->isActive()) {
                    $variant->status = VariantStatus::Active;
                }

                $variant->position = $position;
                [$variant->name, $variant->name_bn] = self::names($values);
            }

            $kept[$variant->id] = true;

            if ($variant->isActive() && ($default === null || ($variant->is_default && ! $default->is_default))) {
                $default = $variant;
            }
        }

        foreach ($existing as $variant) {
            if (! isset($kept[$variant->id]) && $variant->isActive()) {
                $variant->status = VariantStatus::Archived;
            }
        }

        foreach ($existing->merge($product->variants()->whereNotIn('id', $existing->modelKeys())->get()) as $variant) {
            $variant->is_default = $default !== null && $variant->id === $default->id;
            $changed = $this->save($variant, $actorId) || $changed;
        }

        return $changed;
    }

    /**
     * @param  list<ProductAttributeValue>  $values
     */
    private function newVariant(Product $product, ?VariantData $row, ProductData $data, string $field, array $values): ProductVariant
    {
        if (! $this->canChangePrices) {
            throw new AuthorizationException('Changing a selling price or adding a priced variant needs the catalog.update_price permission.');
        }

        $defaults = $data->variantDefaults;
        $price = $row->price ?? $defaults?->price;

        if ($price === null) {
            $label = $values === [] ? 'the variant' : '"'.self::names($values)[0].'"';
            throw ValidationException::withMessages(["{$field}.price" => "A price is required for {$label}."]);
        }

        $compareAt = $row !== null ? $row->compareAtPrice : $defaults?->compareAtPrice;
        self::assertCompareAt($price, $compareAt, $field);

        $variant = new ProductVariant;
        $variant->product_id = $product->id;
        $variant->sku = $row->sku ?? $this->skus->generate($product->id, $values);
        $variant->barcode = $row?->barcode;
        [$variant->name, $variant->name_bn] = $values === [] ? [null, null] : self::names($values);
        $variant->price = $price;
        $variant->compare_at_price = $compareAt;
        $variant->cost_price = $row->costPrice ?? $defaults->costPrice ?? Money::zero();
        $variant->wholesale_price = $row !== null ? $row->wholesalePrice : $defaults?->wholesalePrice;
        $variant->weight_grams = $row->weightGrams ?? $defaults->weightGrams ?? 0;
        $variant->status = $row->status ?? VariantStatus::Active;
        $variant->is_default = false;

        return $variant;
    }

    /** A row edits an existing variant; null "keep" fields stay as they are. */
    private function applyRow(ProductVariant $variant, VariantData $row, string $field): void
    {
        if ($row->sku !== null) {
            $variant->sku = $row->sku;
        }
        $variant->barcode = $row->barcode;
        if ($row->price !== null) {
            $variant->price = $row->price;
        }
        $variant->compare_at_price = $row->compareAtPrice;
        if ($row->costPrice !== null) {
            $variant->cost_price = $row->costPrice;
        }
        $variant->wholesale_price = $row->wholesalePrice;
        if ($row->weightGrams !== null) {
            $variant->weight_grams = $row->weightGrams;
        }
        if ($row->status !== null) {
            $variant->status = $row->status;
        }

        self::assertCompareAt($variant->price, $variant->compare_at_price, $field);
    }

    /** Saves when dirty and audits price and cost changes. */
    private function save(ProductVariant $variant, ?int $actorId): bool
    {
        if (! $variant->isDirty()) {
            return false;
        }

        if (! $this->canChangePrices && (! $variant->exists || $variant->isDirty(['price', 'compare_at_price', 'wholesale_price']))) {
            throw new AuthorizationException('Changing a selling price or adding a priced variant needs the catalog.update_price permission.');
        }

        $audits = [];
        if ($variant->exists && $variant->isDirty(['price', 'compare_at_price'])) {
            $audits[] = [AuditAction::PriceChanged, ['price', 'compare_at_price']];
        }
        if ($variant->exists && $variant->isDirty('cost_price')) {
            $audits[] = [AuditAction::CostChanged, ['cost_price']];
        }

        $before = [];
        foreach ($audits as [, $fields]) {
            foreach ($fields as $f) {
                $before[$f] = $variant->getOriginal($f);
            }
        }

        $variant->save();

        foreach ($audits as [$action, $fields]) {
            AuditSensitive::query()->create([
                'user_id' => $actorId,
                'action' => $action,
                'model_type' => $variant->getMorphClass(),
                'model_id' => $variant->id,
                'before' => array_map(self::plain(...), array_intersect_key($before, array_flip($fields))),
                'after' => array_map(fn (string $f): ?string => self::plain($variant->getAttribute($f)), array_combine($fields, $fields)),
            ]);
        }

        return true;
    }

    private static function plain(mixed $money): ?string
    {
        return $money instanceof Money ? $money->toDecimal() : null;
    }

    private static function assertCompareAt(Money $price, ?Money $compareAt, string $field): void
    {
        if ($compareAt !== null && ! $compareAt->greaterThan($price)) {
            throw ValidationException::withMessages(["{$field}.compare_at_price" => 'The compare-at price must be higher than the price.']);
        }
    }

    /**
     * "Red / Large" and "লাল / বড়" (Bangla falls back to the English value).
     *
     * @param  list<ProductAttributeValue>  $values
     * @return array{string, string}
     */
    private static function names(array $values): array
    {
        return [
            implode(' / ', array_map(static fn (ProductAttributeValue $v): string => $v->value, $values)),
            implode(' / ', array_map(static fn (ProductAttributeValue $v): string => $v->value_bn ?? $v->value, $values)),
        ];
    }

    /**
     * @param  list<list<ProductAttributeValue>>  $sets
     * @return list<list<ProductAttributeValue>>
     */
    private static function cartesian(array $sets): array
    {
        $result = [[]];

        foreach ($sets as $set) {
            $next = [];
            foreach ($result as $prefix) {
                foreach ($set as $value) {
                    $next[] = [...$prefix, $value];
                }
            }
            $result = $next;
        }

        return $result;
    }
}
