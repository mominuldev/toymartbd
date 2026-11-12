<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront\Account;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductListing;
use App\Domain\Customer\Actions\AddToWishlist;
use App\Domain\Customer\Actions\RemoveFromWishlist;
use App\Domain\Customer\Models\WishlistItem;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\Account\AccountReadRequest;
use App\Http\Requests\Storefront\Account\WishlistRequest;
use App\Http\Resources\Storefront\Account\WishlistItemResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The customer's wishlist (P4-STO-11). Cards are read live from the catalog on every
 * request — price, was-price and stock are never stored with the saved item.
 */
final class WishlistController extends ApiController
{
    /** `GET /account/wishlist` — newest first. */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    public function index(AccountReadRequest $request): AnonymousResourceCollection
    {
        $items = WishlistItem::query()->where('customer_id', $request->customer()->id)->orderByDesc('id')->get();

        return WishlistItemResource::collection(self::withLiveProducts($items));
    }

    /** `POST /account/wishlist` — save a toy; saving it again is a no-op (200). */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    #[OpenApiResponse(422, 'Unknown or unpublished product, or the list is full (`validation-error`)')]
    public function store(WishlistRequest $request, AddToWishlist $add): JsonResponse
    {
        $item = $add->handle($request->customer(), $request->productId());
        $resource = new WishlistItemResource(self::withLiveProducts(new Collection([$item]))->first());

        return $resource->response()->setStatusCode($item->wasRecentlyCreated ? 201 : 200);
    }

    /** `DELETE /account/wishlist/{product}` — unsave; a no-op when it was not saved. */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    public function destroy(AccountReadRequest $request, int $product, RemoveFromWishlist $remove): Response
    {
        $remove->handle($request->customer(), $product);

        return response()->noContent();
    }

    /**
     * @param  Collection<int, WishlistItem>  $items
     * @return Collection<int, WishlistItem>
     */
    private static function withLiveProducts(Collection $items): Collection
    {
        $products = Product::query()->published()->with(ProductListing::RELATIONS)
            ->whereKey($items->pluck('product_id')->unique()->values()->all())
            ->get()->keyBy('id');

        return $items->each(static fn (WishlistItem $item) => $item->setRelation('product', $products->get($item->product_id)));
    }
}
