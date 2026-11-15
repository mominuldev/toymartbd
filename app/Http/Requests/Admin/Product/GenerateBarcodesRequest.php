<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Domain\Catalog\Models\Product;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /products/{product}/barcodes` — give this product's variants without a barcode an
 * in-store EAN-13 (GenerateVariantBarcodes). Needs catalog.update. `variant_ids` (optional)
 * picks some of ITS variants, any status; without it every active variant without a barcode
 * gets one. A barcode that is already set is never replaced.
 */
final class GenerateBarcodesRequest extends ApiFormRequest
{
    public const int MAX_VARIANTS = 500;

    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product && ($this->user()?->can('update', $product) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $product = $this->route('product');
        $productId = $product instanceof Product ? $product->id : 0;

        return [
            /** Some of this product's variants (any status); omit for every active variant without a barcode. */
            'variant_ids' => ['sometimes', 'array', 'min:1', 'max:'.self::MAX_VARIANTS],
            'variant_ids.*' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')->where('product_id', $productId)],
        ];
    }

    /** @return list<int>|null */
    public function variantIds(): ?array
    {
        if (! $this->has('variant_ids')) {
            return null;
        }

        /** @var list<int|string> $ids */
        $ids = array_values((array) $this->validated('variant_ids'));

        return array_map(intval(...), $ids);
    }
}
