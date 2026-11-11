<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Catalog\Models\Brand;
use App\Http\Resources\Admin\BrandResource as AdminBrandResource;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A brand on the storefront: `{ name, name_bn, slug, description, description_bn, logo_url }`.
 * No ids or timestamps — the slug is the public key.
 *
 * @property Brand $resource
 */
#[SchemaName('StorefrontBrand')]
final class StorefrontBrandResource extends ApiResource
{
    /**
     * @return array{name: string, name_bn: string|null, slug: string, description: string|null, description_bn: string|null, logo_url: string|null}
     */
    public function toArray(Request $request): array
    {
        $brand = $this->resource;

        return [
            'name' => $brand->name,
            'name_bn' => $brand->name_bn,
            'slug' => $brand->slug,
            'description' => $brand->description,
            'description_bn' => $brand->description_bn,
            /** @var string|null */
            'logo_url' => AdminBrandResource::logoUrl($brand),
        ];
    }
}
