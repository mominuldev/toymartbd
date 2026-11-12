<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront\Account;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\WishlistItem;
use App\Http\Resources\ApiResource;
use App\Http\Resources\Storefront\StorefrontProductCardResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One saved toy with its LIVE card — price, was-price and stock read now, never copied
 * at save time. `available` is false once the product is archived or unpublished; the
 * card is then null and the storefront offers only "remove". The controller sets the
 * `product` relation to the published product (with ProductListing::RELATIONS) or null.
 *
 * @property WishlistItem $resource
 */
#[SchemaName('StorefrontWishlistItem')]
final class WishlistItemResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $item = $this->resource;
        /** @var Product|null $product */
        $product = $item->getRelationValue('product');
        $live = $product !== null;

        return [
            'product_id' => $item->product_id,
            /** @var string|null */
            'saved_at' => $item->created_at?->format('Y-m-d\TH:i:sP'),
            'available' => $live,
            /** @var StorefrontProductCardResource|null */
            'product' => $live ? new StorefrontProductCardResource($product) : null,
        ];
    }
}
