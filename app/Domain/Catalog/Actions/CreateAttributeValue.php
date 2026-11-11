<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\DTOs\AttributeValueData;
use App\Domain\Catalog\Enums\AttributeType;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Services\AttributeSlugs;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Add a value to an attribute, after its existing values. A swatch (`color_hex`)
 * is kept only for colour-type attributes.
 */
final readonly class CreateAttributeValue
{
    public function __construct(private AttributeSlugs $slugs) {}

    public function handle(ProductAttribute $attribute, AttributeValueData $data): ProductAttributeValue
    {
        try {
            return DB::transaction(function () use ($attribute, $data): ProductAttributeValue {
                $attribute = ProductAttribute::query()->lockForUpdate()->findOrFail($attribute->id);
                $max = ProductAttributeValue::query()->where('attribute_id', $attribute->id)->max('position');

                return ProductAttributeValue::query()->create([
                    'attribute_id' => $attribute->id,
                    'value' => $data->value,
                    'value_bn' => $data->valueBn,
                    'slug' => $data->slug ?? $this->slugs->forValue($attribute->id, $data->value, $data->valueBn),
                    'color_hex' => $attribute->type === AttributeType::Color ? $data->colorHex : null,
                    'position' => is_numeric($max) ? (int) $max + 1 : 0,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => __('validation.unique', ['attribute' => 'slug'])]);
        }
    }
}
