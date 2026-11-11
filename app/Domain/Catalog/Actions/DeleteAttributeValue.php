<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Exceptions\CatalogConflict;
use App\Domain\Catalog\Models\ProductAttributeValue;
use Illuminate\Support\Facades\DB;

/**
 * Delete an attribute value nobody uses. Refused with a 409 while any variant
 * references it (`variant_attribute_values.attribute_value_id`) — see
 * DeleteAttribute for why values are deleted rather than archived.
 */
final class DeleteAttributeValue
{
    public function handle(ProductAttributeValue $value): void
    {
        DB::transaction(function () use ($value): void {
            $value = ProductAttributeValue::query()->lockForUpdate()->findOrFail($value->id);

            if ($value->isUsedByVariants()) {
                throw CatalogConflict::valueInUse();
            }

            $value->delete();
        });
    }
}
