<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Domain\Catalog\Models\Product;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /products/{product}/duplicate` — no body. Needs catalog.view (to read the
 * source) and catalog.create (the copy is a new product).
 */
final class DuplicateProductRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');
        $user = $this->user();

        return $product instanceof Product
            && $user !== null
            && $user->can('view', $product)
            && $user->can('create', Product::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
