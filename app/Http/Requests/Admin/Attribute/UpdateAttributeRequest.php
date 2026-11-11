<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Attribute;

use App\Domain\Catalog\Enums\AttributeType;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Http\Requests\Admin\Brand\StoreBrandRequest;
use App\Http\Requests\Admin\Category\StoreCategoryRequest;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /attributes/{attribute}` — every field optional; only the fields sent
 * change. Changing `type` away from `color` clears the values' swatches.
 */
final class UpdateAttributeRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $attribute = $this->route('attribute');

        return $attribute instanceof ProductAttribute && ($this->user()?->can('update', $attribute) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $attribute = $this->route('attribute');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'name_bn' => ['sometimes', 'nullable', 'string', 'max:80'],
            'slug' => [
                'sometimes', 'nullable', 'string', 'max:'.ProductAttribute::SLUG_MAX, 'regex:'.StoreBrandRequest::SLUG_PATTERN,
                Rule::unique('product_attributes', 'slug')->ignore($attribute instanceof ProductAttribute ? $attribute->id : null),
            ],
            'type' => ['sometimes', Rule::enum(AttributeType::class)],
            'is_variant_defining' => ['sometimes', 'boolean'],
            'is_filterable' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
    }

    /**
     * @return array{name?: string, name_bn?: string|null, slug?: string, type?: AttributeType, is_variant_defining?: bool, is_filterable?: bool, position?: int}
     */
    public function changes(): array
    {
        $changes = [];

        if ($this->has('name')) {
            $changes['name'] = $this->string('name')->toString();
        }

        if ($this->has('name_bn')) {
            $changes['name_bn'] = StoreCategoryRequest::nullableString($this->input('name_bn'));
        }

        $slug = StoreCategoryRequest::nullableString($this->input('slug'));
        if ($slug !== null) {
            $changes['slug'] = $slug;
        }

        $type = $this->enum('type', AttributeType::class);
        if ($type !== null) {
            $changes['type'] = $type;
        }

        foreach (['is_variant_defining', 'is_filterable'] as $flag) {
            if ($this->has($flag)) {
                $changes[$flag] = $this->boolean($flag);
            }
        }

        if ($this->has('position')) {
            $changes['position'] = $this->integer('position');
        }

        return $changes;
    }
}
