<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\DTOs\RelationData;
use App\Domain\Catalog\Enums\RelationType;
use App\Domain\Catalog\Enums\VariantStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductRelation;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Replaces a product's hand-curated relations (`is_auto = false`). Rule-generated
 * rows are left alone, except that curating a pair a rule produced turns it into a
 * human decision. No transaction of its own.
 *
 * Write-time guardrails (rule 25c): a product never relates to itself, a target must
 * exist, and an `upsell` target must cost more than this product — compared on the
 * default variants' selling prices. The read-time guardrails (stock, archived,
 * caps) belong to the recommendation resolver.
 */
final class ProductRelationsWriter
{
    /**
     * @param  list<RelationData>  $relations
     * @return bool whether anything changed
     *
     * @throws ValidationException
     */
    public function sync(Product $product, array $relations, ?int $actorId): bool
    {
        $this->validate($product, $relations);

        $changed = false;
        $keep = [];

        foreach ($relations as $relation) {
            $row = ProductRelation::query()->firstOrNew([
                'product_id' => $product->id,
                'related_product_id' => $relation->relatedProductId,
                'type' => $relation->type->value,
            ]);

            if (! $row->exists) {
                $row->created_by = $actorId;
            }

            $row->fill([
                'position' => $relation->position,
                'reason_note' => $relation->reasonNote,
                'is_auto' => false,
                'rule_id' => null,
            ]);

            if ($row->isDirty()) {
                $row->save();
                $changed = true;
            }

            $keep[] = $row->id;
        }

        $removed = ProductRelation::query()
            ->where('product_id', $product->id)
            ->where('is_auto', false)
            ->whereNotIn('id', $keep)
            ->delete();

        return $changed || $removed > 0;
    }

    /**
     * @param  list<RelationData>  $relations
     */
    private function validate(Product $product, array $relations): void
    {
        $errors = [];
        $targetIds = array_map(static fn (RelationData $r): int => $r->relatedProductId, $relations);
        $targets = Product::query()->whereIn('id', $targetIds)->with('defaultVariant')->get()->keyBy('id');
        $sourcePrice = $this->price($product);
        $seen = [];

        foreach ($relations as $i => $relation) {
            $field = "relations.{$i}.related_product_id";
            $pair = $relation->relatedProductId.':'.$relation->type->value;

            if ($relation->relatedProductId === $product->id) {
                $errors[$field][] = 'A product cannot be related to itself.';

                continue;
            }

            if (isset($seen[$pair])) {
                $errors[$field][] = 'This product is already listed with the same relation type.';

                continue;
            }
            $seen[$pair] = true;

            $target = $targets->get($relation->relatedProductId);
            if ($target === null) {
                $errors[$field][] = 'The related product does not exist.';

                continue;
            }

            if ($relation->type === RelationType::Upsell) {
                $targetPrice = $target->defaultVariant?->status === VariantStatus::Active ? $target->defaultVariant->price : null;

                if ($sourcePrice === null || $targetPrice === null || ! $targetPrice->greaterThan($sourcePrice)) {
                    $errors[$field][] = 'An upsell must cost more than this product.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function price(Product $product): ?Money
    {
        $default = $product->defaultVariant()->first();

        return $default?->price;
    }
}
