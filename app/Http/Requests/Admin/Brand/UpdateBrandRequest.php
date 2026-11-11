<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Brand;

use App\Domain\Catalog\Models\Brand;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /brands/{brand}` — every field optional; only the fields sent change.
 * `slug: null` (or omitted) keeps the current slug: a rename never moves the URL.
 * `is_active: true` restores an archived brand.
 */
final class UpdateBrandRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $brand = $this->route('brand');

        return $brand instanceof Brand && ($this->user()?->can('update', $brand) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $brand = $this->route('brand');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'name_bn' => ['sometimes', 'nullable', 'string', 'max:120'],
            'slug' => [
                'sometimes', 'nullable', 'string', 'max:'.Brand::SLUG_MAX, 'regex:'.StoreBrandRequest::SLUG_PATTERN,
                Rule::unique('brands', 'slug')->ignore($brand instanceof Brand ? $brand->id : null),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'description_bn' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{name?: string, name_bn?: string|null, slug?: string, description?: string|null, description_bn?: string|null, is_active?: bool}
     */
    public function changes(): array
    {
        $changes = [];

        if ($this->has('name')) {
            $changes['name'] = $this->string('name')->toString();
        }

        foreach (['name_bn', 'description', 'description_bn'] as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                $changes[$field] = is_string($value) && $value !== '' ? $value : null;
            }
        }

        $slug = $this->input('slug');
        if (is_string($slug) && $slug !== '') {
            $changes['slug'] = $slug;
        }

        if ($this->has('is_active')) {
            $changes['is_active'] = $this->boolean('is_active');
        }

        return $changes;
    }
}
