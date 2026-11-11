<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\AttributeType;
use App\Domain\Catalog\Models\ProductAttributeValue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Edit one attribute value. Only the keys present in `$changes` are written. */
final class UpdateAttributeValue
{
    private const array FIELDS = ['value', 'value_bn', 'slug', 'color_hex'];

    /**
     * @param  array{value?: string, value_bn?: string|null, slug?: string, color_hex?: string|null}  $changes
     */
    public function handle(ProductAttributeValue $value, array $changes): ProductAttributeValue
    {
        try {
            return DB::transaction(function () use ($value, $changes): ProductAttributeValue {
                $value = ProductAttributeValue::query()->with('attribute')->lockForUpdate()->findOrFail($value->id);

                if ($value->attribute->type !== AttributeType::Color) {
                    unset($changes['color_hex']);
                }

                $value->fill(array_intersect_key($changes, array_flip(self::FIELDS)));

                if ($value->isDirty()) {
                    $value->save();
                }

                return $value;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => __('validation.unique', ['attribute' => 'slug'])]);
        }
    }
}
