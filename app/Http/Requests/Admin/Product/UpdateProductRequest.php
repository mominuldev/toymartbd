<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Domain\Catalog\Models\Product;

/**
 * `PUT /products/{product}` — needs catalog.update. The whole document, variant
 * matrix included; `slug` null (or omitted) keeps the current slug, `relations`
 * omitted keeps the curated relations, `cost_price` omitted keeps a variant's cost.
 *
 * Field-level abilities, enforced where the domain sees what changes (403 otherwise):
 * changing a selling price or adding a variant needs catalog.update_price; sending
 * any `cost_price` needs catalog.view_cost.
 */
final class UpdateProductRequest extends ProductDocumentRequest
{
    public function authorize(): bool
    {
        $product = $this->product();

        return $product !== null && ($this->user()?->can('update', $product) ?? false);
    }

    protected function product(): ?Product
    {
        $product = $this->route('product');

        return $product instanceof Product ? $product : null;
    }
}
