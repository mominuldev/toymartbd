<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Customer\Models\District;
use App\Domain\Fulfilment\Services\DeliveryChargeResolver;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\DeliveryQuoteRequest;
use App\Http\Resources\Storefront\DeliveryQuoteResource;
use App\Http\Resources\Storefront\DeliveryRatesResource;
use App\Http\Resources\Storefront\StorefrontDistrictResource;
use App\Http\Resources\Storefront\StorefrontUpazilaResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Delivery charge and time before the cart (P4-STO-06, docs/api/delivery.md). Every
 * figure comes from DeliveryChargeResolver — the code path checkout uses — so the PDP
 * quote and the order's charge cannot disagree. The storefront never computes a charge.
 */
final class DeliveryController extends ApiController
{
    /** Dhaka division first in the selector (A12.2), then the rest by name. */
    public const int DHAKA_DIVISION_ID = 6;

    /**
     * `GET /delivery-quote` — the charge, free-delivery progress, delivery window and
     * cut-off for one destination and (optionally) one variant × quantity. Not cached:
     * the window moves at the cut-off and the free-delivery figure follows the price.
     */
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(503, 'No active courier provider is configured (`service-unavailable`)')]
    public function quote(DeliveryQuoteRequest $request, DeliveryChargeResolver $resolver): DeliveryQuoteResource
    {
        return new DeliveryQuoteResource($resolver->quote($request->district(), $request->upazila(), $request->parcel()));
    }

    /** `GET /delivery-rates` — the published rate table (zone charges, ETAs, free-delivery threshold). */
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(503, 'No active courier provider is configured (`service-unavailable`)')]
    public function rates(DeliveryChargeResolver $resolver): DeliveryRatesResource
    {
        return new DeliveryRatesResource($resolver->publishedRates());
    }

    /** `GET /districts` — all 64 districts, Dhaka division first, then by division and district name. */
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    public function districts(): AnonymousResourceCollection
    {
        $districts = District::query()
            ->with(['division', 'upazilas'])
            ->get()
            ->sortBy([
                static fn (District $a, District $b): int => ($a->division_id !== self::DHAKA_DIVISION_ID) <=> ($b->division_id !== self::DHAKA_DIVISION_ID),
                static fn (District $a, District $b): int => strcmp($a->division->name, $b->division->name),
                static fn (District $a, District $b): int => strcmp($a->name, $b->name),
            ])
            ->values();

        return StorefrontDistrictResource::collection($districts);
    }

    /** `GET /districts/{district}/upazilas` — the district's upazilas by name, each with `serviceable`. */
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(404, 'No district has this id')]
    public function upazilas(District $district): AnonymousResourceCollection
    {
        $upazilas = $district->upazilas()->orderBy('name')->get()->each(fn ($u) => $u->setRelation('district', $district));

        return StorefrontUpazilaResource::collection($upazilas);
    }
}
