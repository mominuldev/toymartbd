<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\AttributeType;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edit an attribute. Only the keys present in `$changes` are written. Changing the
 * type away from `color` clears the values' swatches, which would otherwise linger
 * as invisible data.
 */
final class UpdateAttribute
{
    private const array FIELDS = ['name', 'name_bn', 'slug', 'type', 'is_variant_defining', 'is_filterable', 'position'];

    /**
     * @param  array{name?: string, name_bn?: string|null, slug?: string, type?: AttributeType, is_variant_defining?: bool, is_filterable?: bool, position?: int}  $changes
     */
    public function handle(ProductAttribute $attribute, array $changes): ProductAttribute
    {
        try {
            return DB::transaction(function () use ($attribute, $changes): ProductAttribute {
                $attribute = ProductAttribute::query()->lockForUpdate()->findOrFail($attribute->id);
                $attribute->fill(array_intersect_key($changes, array_flip(self::FIELDS)));

                if (! $attribute->isDirty()) {
                    return $attribute;
                }

                $attribute->save();

                if ($attribute->wasChanged('type') && $attribute->type !== AttributeType::Color) {
                    ProductAttributeValue::query()->where('attribute_id', $attribute->id)->update(['color_hex' => null]);
                }

                return $attribute;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => __('validation.unique', ['attribute' => 'slug'])]);
        }
    }
}
