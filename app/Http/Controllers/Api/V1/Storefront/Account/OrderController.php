<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront\Account;

use App\Domain\Ordering\Queries\CustomerOrders;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\Account\AccountOrdersRequest;
use App\Http\Requests\Storefront\Account\AccountReadRequest;
use App\Http\Resources\Storefront\Account\StorefrontOrderSummaryResource;
use App\Http\Resources\Storefront\StorefrontOrderResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The customer's own order history (P4-STO-11). Every read is scoped to the signed-in
 * customer (CustomerOrders): another customer's order number is a plain 404, the same as
 * a number that never existed.
 */
final class OrderController extends ApiController
{
    public const int PER_PAGE = 10;

    /** `GET /account/orders` — newest first, cursor-paginated, optionally one `group`; plus the per-group counts. */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    #[OpenApiResponse(422, 'Unknown `group` or an unlisted query parameter (`validation-error`)')]
    public function index(AccountOrdersRequest $request, CustomerOrders $orders): AnonymousResourceCollection
    {
        $customerId = $request->customer()->id;

        return StorefrontOrderSummaryResource::collection($orders->paginate($customerId, self::PER_PAGE, $request->cursor(), $request->group()))
            ->additional(['summary' => $orders->groupCounts($customerId)]);
    }

    /** `GET /account/orders/{number}` — one of the customer's own orders, full detail. */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    #[OpenApiResponse(404, 'No order with this number belongs to the customer (`not-found`)')]
    public function show(AccountReadRequest $request, string $number, CustomerOrders $orders): StorefrontOrderResource
    {
        return new StorefrontOrderResource($orders->find($request->customer()->id, $number) ?? throw new NotFoundHttpException);
    }
}
