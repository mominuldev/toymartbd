<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Exceptions\CatalogConflict;
use App\Domain\Catalog\Models\ProductAttribute;
use Illuminate\Support\Facades\DB;

/**
 * Delete an attribute and its values.
 *
 * Decision: attributes are hard-deleted, not archived. §3.1 gives
 * `product_attributes` no status column, and an attribute no variant uses has no
 * history worth keeping — it is configuration, like a typo'd "Colour" next to
 * "Color". The guard is what matters: while any variant references one of its
 * values (`variant_attribute_values`), the delete is refused with a 409, so a
 * variant never loses the combination that names it.
 */
final class DeleteAttribute
{
    public function handle(ProductAttribute $attribute): void
    {
        DB::transaction(function () use ($attribute): void {
            $attribute = ProductAttribute::query()->lockForUpdate()->findOrFail($attribute->id);

            $inUse = DB::table('variant_attribute_values')->where('attribute_id', $attribute->id)->exists();
            if ($inUse) {
                throw CatalogConflict::attributeInUse();
            }

            $attribute->values()->delete();
            $attribute->delete();
        });
    }
}
