<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Category;

use App\Domain\Catalog\Models\Category;
use App\Http\Requests\Admin\Brand\StoreBrandRequest;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /categories/{category}` — every field optional; only the fields sent
 * change. `slug: null` (or omitted) keeps the current slug. The parent and order
 * change only through `POST /categories/{category}/move`.
 */
final class UpdateCategoryRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $category = $this->route('category');

        return $category instanceof Category && ($this->user()?->can('update', $category) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'name_bn' => ['sometimes', 'nullable', 'string', 'max:120'],
            'slug' => [
                'sometimes', 'nullable', 'string', 'max:'.Category::SLUG_MAX, 'regex:'.StoreBrandRequest::SLUG_PATTERN, Rule::notIn(Category::RESERVED_SLUGS),
                Rule::unique('categories', 'slug')->ignore($category instanceof Category ? $category->id : null),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'description_bn' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:'.StoreCategoryRequest::ICON_PATTERN],
            'is_featured' => ['sometimes', 'boolean'],
            'show_in_menu' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{name?: string, name_bn?: string|null, slug?: string, description?: string|null, description_bn?: string|null, icon?: string|null, is_featured?: bool, show_in_menu?: bool, is_active?: bool}
     */
    public function changes(): array
    {
        $changes = [];

        if ($this->has('name')) {
            $changes['name'] = $this->string('name')->toString();
        }

        foreach (['name_bn', 'description', 'description_bn', 'icon'] as $field) {
            if ($this->has($field)) {
                $changes[$field] = StoreCategoryRequest::nullableString($this->input($field));
            }
        }

        $slug = StoreCategoryRequest::nullableString($this->input('slug'));
        if ($slug !== null) {
            $changes['slug'] = $slug;
        }

        foreach (['is_featured', 'show_in_menu', 'is_active'] as $flag) {
            if ($this->has($flag)) {
                $changes[$flag] = $this->boolean($flag);
            }
        }

        return $changes;
    }
}
