<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Models\Product;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A product's BSTI compliance block — the same shape as `Product.compliance`, returned
 * by the certificate endpoints so the form updates it without reloading the document.
 *
 * @property Product $resource
 */
#[SchemaName('ProductCompliance')]
final class ProductComplianceResource extends ApiResource
{
    /**
     * @return array{bsti_certificate_no: string|null, bsti_expires_on: string|null, expired: bool, certificate: array{name: string, size_bytes: int}|null}
     */
    public function toArray(Request $request): array
    {
        return self::block($this->resource);
    }

    /**
     * @return array{bsti_certificate_no: string|null, bsti_expires_on: string|null, expired: bool, certificate: array{name: string, size_bytes: int}|null}
     */
    public static function block(Product $product): array
    {
        return [
            'bsti_certificate_no' => $product->bsti_certificate_no,
            'bsti_expires_on' => $product->bsti_expires_on?->format('Y-m-d'),
            'expired' => $product->bsti_expires_on !== null && $product->bsti_expires_on->format('Y-m-d') < now('Asia/Dhaka')->format('Y-m-d'),
            'certificate' => $product->bsti_certificate_path === null ? null : [
                'name' => (string) $product->bsti_certificate_name,
                'size_bytes' => (int) $product->bsti_certificate_size,
            ],
        ];
    }
}
