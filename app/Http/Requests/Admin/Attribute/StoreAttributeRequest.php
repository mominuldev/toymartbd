<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Attribute;

use App\Domain\Catalog\DTOs\AttributeData;
use App\Domain\Catalog\Enums\AttributeType;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Http\Requests\Admin\Brand\StoreBrandRequest;
use App\Http\Requests\Admin\Category\StoreCategoryRequest;
use App\Http\Requests\ApiFormRequest;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /attributes`. `type` is `select` (default), `color` (values carry a swatch)
 * or `text`. When `slug` is omitted it is generated from `name` (or `name_bn`).
 */
final class StoreAttributeRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ProductAttribute::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'name_bn' => ['nullable', 'string', 'max:80'],
            'slug' => ['nullable', 'string', 'max:'.ProductAttribute::SLUG_MAX, 'regex:'.StoreBrandRequest::SLUG_PATTERN, Rule::unique('product_attributes', 'slug')],
            'type' => ['sometimes', Rule::enum(AttributeType::class)],
            'is_variant_defining' => ['sometimes', 'boolean'],
            'is_filterable' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [StoreBrandRequest::slugCanBeGenerated(...)];
    }

    public function toData(): AttributeData
    {
        return new AttributeData(
            name: $this->string('name')->toString(),
            nameBn: StoreCategoryRequest::nullableString($this->input('name_bn')),
            slug: StoreCategoryRequest::nullableString($this->input('slug')),
            type: $this->enum('type', AttributeType::class) ?? AttributeType::Select,
            isVariantDefining: $this->has('is_variant_defining') ? $this->boolean('is_variant_defining') : true,
            isFilterable: $this->has('is_filterable') ? $this->boolean('is_filterable') : true,
        );
    }
}
