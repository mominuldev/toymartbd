<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Attribute;

use App\Domain\Catalog\Models\ProductAttribute;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /attributes/{attribute}/values/reorder` — `{ ids: [3, 1, 2] }`, every value
 * id of the attribute exactly once, in the new display order.
 */
final class ReorderAttributeValuesRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $attribute = $this->route('attribute');

        return $attribute instanceof ProductAttribute && ($this->user()?->can('update', $attribute) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['present', 'array', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
        ];
    }

    /** @return list<int> */
    public function ids(): array
    {
        $ids = $this->input('ids');

        return is_array($ids) ? array_values(array_map(intval(...), $ids)) : [];
    }
}
