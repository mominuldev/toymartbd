<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Attribute;

use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Http\Requests\Admin\Brand\StoreBrandRequest;
use App\Http\Requests\Admin\Category\StoreCategoryRequest;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/** `PATCH /attributes/{attribute}/values/{value}` — only the fields sent change. */
final class UpdateAttributeValueRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $value = $this->route('value');

        return $value instanceof ProductAttributeValue && ($this->user()?->can('update', $value) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $value = $this->route('value');

        return [
            'value' => ['sometimes', 'required', 'string', 'max:80'],
            'value_bn' => ['sometimes', 'nullable', 'string', 'max:80'],
            'slug' => [
                'sometimes', 'nullable', 'string', 'max:'.ProductAttributeValue::SLUG_MAX, 'regex:'.StoreBrandRequest::SLUG_PATTERN,
                Rule::unique('product_attribute_values', 'slug')
                    ->where('attribute_id', $value instanceof ProductAttributeValue ? $value->attribute_id : 0)
                    ->ignore($value instanceof ProductAttributeValue ? $value->id : null),
            ],
            'color_hex' => ['sometimes', 'nullable', 'string', 'regex:'.StoreAttributeValueRequest::HEX_PATTERN],
        ];
    }

    /**
     * @return array{value?: string, value_bn?: string|null, slug?: string, color_hex?: string|null}
     */
    public function changes(): array
    {
        $changes = [];

        if ($this->has('value')) {
            $changes['value'] = $this->string('value')->toString();
        }

        if ($this->has('value_bn')) {
            $changes['value_bn'] = StoreCategoryRequest::nullableString($this->input('value_bn'));
        }

        $slug = StoreCategoryRequest::nullableString($this->input('slug'));
        if ($slug !== null) {
            $changes['slug'] = $slug;
        }

        if ($this->has('color_hex')) {
            $hex = StoreCategoryRequest::nullableString($this->input('color_hex'));
            $changes['color_hex'] = $hex !== null ? strtoupper($hex) : null;
        }

        return $changes;
    }
}
