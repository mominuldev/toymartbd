<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Domain\Catalog\Models\Product;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /products/{product}/images/reorder` — `{ ids: [3, 1, 2] }`, every image id
 * of the product exactly once, in display order. Needs catalog.update.
 */
final class ReorderProductImagesRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product && ($this->user()?->can('update', $product) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['present', 'array', 'max:100'],
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
