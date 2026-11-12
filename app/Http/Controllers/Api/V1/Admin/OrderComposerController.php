<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Customer\Models\District;
use App\Domain\Ordering\Actions\PlaceManualOrder;
use App\Domain\Ordering\Queries\OrderComposerLookups;
use App\Domain\Ordering\Services\OrderQuotes;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Storefront\DeliveryController;
use App\Http\Requests\Admin\Order\OrderComposerLookupRequest;
use App\Http\Requests\Admin\Order\OrderQuoteRequest;
use App\Http\Requests\Admin\Order\StoreOrderRequest;
use App\Http\Resources\Admin\OrderComposerCustomerResource;
use App\Http\Resources\Admin\OrderComposerVariantResource;
use App\Http\Resources\Admin\OrderQuoteResource;
use App\Http\Resources\Admin\OrderResource;
use App\Support\PhoneNumber;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The create-order screen (P4-ORD-06, Figma 207:2243): phone lookup first, product
 * picker, address cascade, a live quote, then the order. Every figure comes from the
 * pricing pipeline web checkout uses; the order is placed by PlaceOrder itself (through
 * PlaceManualOrder), so it has exactly a web order's shape with its own `source`.
 * All endpoints need orders.create.
 */
final class OrderComposerController extends ApiController
{
    /** `GET /orders/compose/customer?phone=` — who this number is, saved addresses, open orders. */
    #[OpenApiResponse(422, 'Not a valid Bangladeshi mobile number (`validation-error`)')]
    public function customer(OrderComposerLookupRequest $request, OrderComposerLookups $lookups): OrderComposerCustomerResource
    {
        $phone = PhoneNumber::tryParse($request->string('phone')->toString())
            ?? throw new UnprocessableEntityHttpException((string) __('checkout.phone.invalid'));

        return new OrderComposerCustomerResource(['phone' => $phone] + $lookups->customer($phone));
    }

    /** `GET /orders/compose/variants?q=` — sellable variants by name (EN/BN), SKU or barcode, with stock available now. */
    public function variants(OrderComposerLookupRequest $request, OrderComposerLookups $lookups): AnonymousResourceCollection
    {
        return OrderComposerVariantResource::collection($lookups->variants($request->term()));
    }

    /** `GET /orders/compose/districts` — the 64 districts, Dhaka division first (the storefront's list and rules). */
    public function districts(OrderComposerLookupRequest $request, DeliveryController $delivery): AnonymousResourceCollection
    {
        return $delivery->districts();
    }

    /** `GET /orders/compose/districts/{district}/upazilas` — each with `serviceable`. */
    public function upazilas(OrderComposerLookupRequest $request, District $district, DeliveryController $delivery): AnonymousResourceCollection
    {
        return $delivery->upazilas($district);
    }

    /**
     * `POST /orders/quote` — price the lines (and, once given, the destination and phone)
     * through the checkout pipeline. Writes nothing. `blockers` says what stops it
     * becoming an order; `grand_total` is what `POST /orders` expects back.
     */
    public function quote(OrderQuoteRequest $request, OrderQuotes $quotes): OrderQuoteResource
    {
        $upazila = $request->quoteUpazila();
        $breakdown = $quotes->forNewOrder($request->lines(), $request->text('coupon_code'), $request->quoteDistrict(), $upazila, $request->quotePhone());

        return new OrderQuoteResource($breakdown, OrderQuotes::blockers($breakdown, $upazila));
    }

    /**
     * `POST /orders` — place a phone / Facebook / WhatsApp order. Requires an
     * `Idempotency-Key`: a double submit returns the first order. 201 with the order as
     * `GET /orders/{order}` returns it.
     */
    #[OpenApiResponse(409, 'The total changed since the quote (`price-changed`), stock ran out (`insufficient-stock`), or the key is in flight (`request-in-progress`)')]
    #[OpenApiResponse(422, 'Invalid field, unknown coupon, unsellable line or address not serviced (`validation-error`)')]
    public function store(StoreOrderRequest $request, PlaceManualOrder $place): JsonResponse
    {
        $result = OrderProblems::guard($request, static fn () => $place->handle($request->toData()));
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return (new OrderResource($result->load(OrderResource::RELATIONS)))->response()
            ->setStatusCode(201)
            ->header('Location', '/api/v1/admin/orders/'.$result->id);
    }
}
