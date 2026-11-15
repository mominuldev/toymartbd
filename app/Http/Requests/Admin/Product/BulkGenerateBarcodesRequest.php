<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use Illuminate\Validation\Rule;

/**
 * `POST /products/barcodes` — every active variant without a barcode, of each of
 * `product_ids` (≤ 200), gets an in-store EAN-13 (GenerateVariantBarcodes). Needs
 * catalog.update. A barcode that is already set is never replaced.
 */
final class BulkGenerateBarcodesRequest extends ApiFormRequest
{
    public const int MAX_PRODUCTS = 200;

    public function authorize(): bool
    {
        return $this->user()?->can(Permission::CatalogUpdate->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_PRODUCTS],
            'product_ids.*' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')],
        ];
    }

    /** @return list<int> */
    public function productIds(): array
    {
        /** @var list<int|string> $ids */
        $ids = array_values((array) $this->validated('product_ids'));

        return array_map(intval(...), $ids);
    }
}
