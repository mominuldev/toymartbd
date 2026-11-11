<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Category;

use App\Domain\Catalog\Models\Category;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /categories/{category}/move` — `{ parent_id, position }`. The category
 * becomes the child of `parent_id` (null = a root) at sibling index `position`
 * (0-based; past the end means last). Up/down keeps the parent and changes the
 * position; indent/outdent changes the parent.
 */
final class MoveCategoryRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $category = $this->route('category');

        return $category instanceof Category && ($this->user()?->can('update', $category) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'parent_id' => ['present', 'nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'position' => ['required', 'integer', 'min:0', 'max:10000'],
        ];
    }

    public function parentId(): ?int
    {
        $parentId = $this->input('parent_id');

        return is_numeric($parentId) ? (int) $parentId : null;
    }

    public function position(): int
    {
        return $this->integer('position');
    }
}
