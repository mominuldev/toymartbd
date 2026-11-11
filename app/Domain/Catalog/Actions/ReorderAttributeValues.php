<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Set the display order of an attribute's values. `$orderedIds` must be exactly
 * the attribute's value ids, each once — a partial list would leave the order
 * ambiguous, so it is a 422.
 */
final class ReorderAttributeValues
{
    /**
     * @param  list<int>  $orderedIds
     */
    public function handle(ProductAttribute $attribute, array $orderedIds): ProductAttribute
    {
        return DB::transaction(function () use ($attribute, $orderedIds): ProductAttribute {
            $attribute = ProductAttribute::query()->lockForUpdate()->findOrFail($attribute->id);

            /** @var list<int> $existing */
            $existing = ProductAttributeValue::query()->where('attribute_id', $attribute->id)->lockForUpdate()->pluck('id')->all();

            $given = $orderedIds;
            sort($given);
            sort($existing);

            if ($given !== $existing) {
                throw ValidationException::withMessages(['ids' => 'Send every value id of this attribute exactly once.']);
            }

            foreach ($orderedIds as $position => $id) {
                ProductAttributeValue::query()->whereKey($id)->update(['position' => $position]);
            }

            return $attribute;
        });
    }
}
