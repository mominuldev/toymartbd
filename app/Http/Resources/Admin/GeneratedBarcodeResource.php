<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Models\ProductVariant;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A variant that just got an in-store EAN-13 (`POST /products/{product}/barcodes`,
 * `POST /products/barcodes`).
 *
 * @property ProductVariant $resource
 */
#[SchemaName('GeneratedBarcode')]
final class GeneratedBarcodeResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'product_id' => $this->resource->product_id,
            'sku' => $this->resource->sku,
            /** @var string */
            'barcode' => (string) $this->resource->barcode,
        ];
    }
}
