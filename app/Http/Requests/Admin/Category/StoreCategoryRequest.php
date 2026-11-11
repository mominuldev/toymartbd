<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Category;

use App\Domain\Catalog\DTOs\CategoryData;
use App\Domain\Catalog\Models\Category;
use App\Http\Requests\Admin\Brand\StoreBrandRequest;
use App\Http\Requests\ApiFormRequest;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /categories`. Appended as the last child of `parent_id` (omit or null for a
 * root). When `slug` is omitted it is generated from `name` (or `name_bn`). At most
 * three levels deep; an active category under an archived parent is a 409.
 */
final class StoreCategoryRequest extends ApiFormRequest
{
    /** A Phosphor icon name: "puzzle-piece", "baby". */
    public const string ICON_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function authorize(): bool
    {
        return $this->user()?->can('create', Category::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:'.Category::SLUG_MAX, 'regex:'.StoreBrandRequest::SLUG_PATTERN, Rule::notIn(Category::RESERVED_SLUGS), Rule::unique('categories', 'slug')],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:5000'],
            'description_bn' => ['nullable', 'string', 'max:5000'],
            'icon' => ['nullable', 'string', 'max:64', 'regex:'.self::ICON_PATTERN],
            'is_featured' => ['sometimes', 'boolean'],
            'show_in_menu' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [StoreBrandRequest::slugCanBeGenerated(...)];
    }

    public function toData(): CategoryData
    {
        $parentId = $this->input('parent_id');

        return new CategoryData(
            name: $this->string('name')->toString(),
            nameBn: self::nullableString($this->input('name_bn')),
            slug: self::nullableString($this->input('slug')),
            parentId: is_numeric($parentId) ? (int) $parentId : null,
            description: self::nullableString($this->input('description')),
            descriptionBn: self::nullableString($this->input('description_bn')),
            icon: self::nullableString($this->input('icon')),
            isFeatured: $this->boolean('is_featured'),
            showInMenu: $this->has('show_in_menu') ? $this->boolean('show_in_menu') : true,
            isActive: $this->has('is_active') ? $this->boolean('is_active') : true,
        );
    }

    public static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
