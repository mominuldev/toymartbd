<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Domain\Catalog\Models\Product;

/**
 * `POST /products` — needs catalog.create. Setting the initial prices is part of
 * creating; a `cost_price` anywhere in the document needs catalog.view_cost.
 * When `slug` is omitted it is generated from `name` (or `name_bn`).
 */
final class StoreProductRequest extends ProductDocumentRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    protected function product(): ?Product
    {
        return null;
    }
}
