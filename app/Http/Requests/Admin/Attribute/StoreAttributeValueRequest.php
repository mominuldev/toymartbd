<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Attribute;

use App\Domain\Catalog\DTOs\AttributeValueData;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Http\Requests\Admin\Brand\StoreBrandRequest;
use App\Http\Requests\Admin\Category\StoreCategoryRequest;
use App\Http\Requests\ApiFormRequest;
use App\Support\Slug;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * `POST /attributes/{attribute}/values`. `slug` is unique within the attribute and
 * generated from `value` when omitted. `color_hex` (`#RRGGBB`) is kept only for a
 * colour-type attribute.
 */
final class StoreAttributeValueRequest extends ApiFormRequest
{
    public const string HEX_PATTERN = '/^#[0-9A-Fa-f]{6}$/';

    public function authorize(): bool
    {
        return $this->route('attribute') instanceof ProductAttribute
            && ($this->user()?->can('create', ProductAttributeValue::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $attribute = $this->route('attribute');

        return [
            'value' => ['required', 'string', 'max:80'],
            'value_bn' => ['nullable', 'string', 'max:80'],
            'slug' => [
                'nullable', 'string', 'max:'.ProductAttributeValue::SLUG_MAX, 'regex:'.StoreBrandRequest::SLUG_PATTERN,
                Rule::unique('product_attribute_values', 'slug')->where('attribute_id', $attribute instanceof ProductAttribute ? $attribute->id : 0),
            ],
            'color_hex' => ['nullable', 'string', 'regex:'.self::HEX_PATTERN],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [static function (Validator $validator): void {
            $data = $validator->getData();
            $value = $data['value'] ?? null;
            $valueBn = $data['value_bn'] ?? null;

            if ($validator->errors()->hasAny(['value', 'value_bn', 'slug']) || (is_string($data['slug'] ?? null) && $data['slug'] !== '')) {
                return;
            }

            try {
                Slug::forNames(is_string($value) ? $value : null, is_string($valueBn) ? $valueBn : null);
            } catch (InvalidArgumentException) {
                $validator->errors()->add('slug', 'A URL slug cannot be generated from this value; supply a slug.');
            }
        }];
    }

    public function toData(): AttributeValueData
    {
        $hex = StoreCategoryRequest::nullableString($this->input('color_hex'));

        return new AttributeValueData(
            value: $this->string('value')->toString(),
            valueBn: StoreCategoryRequest::nullableString($this->input('value_bn')),
            slug: StoreCategoryRequest::nullableString($this->input('slug')),
            colorHex: $hex !== null ? strtoupper($hex) : null,
        );
    }
}
