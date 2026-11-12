<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront\Account;

use App\Domain\Customer\Actions\DeleteCustomerAddress;
use App\Domain\Customer\Actions\SaveCustomerAddress;
use App\Domain\Customer\Actions\SetDefaultAddress;
use App\Domain\Customer\Models\Address;
use App\Domain\Customer\Models\Customer;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\Account\AccountReadRequest;
use App\Http\Requests\Storefront\Account\AddressRequest;
use App\Http\Resources\Storefront\Account\StorefrontAddressResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The customer's address book (P4-STO-11, rule 31). Addresses are found only within the
 * signed-in customer's own book; any other id is a 404.
 */
final class AddressController extends ApiController
{
    private const array RELATIONS = ['division', 'district', 'upazila'];

    /** `GET /account/addresses` — default first. */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    public function index(AccountReadRequest $request): AnonymousResourceCollection
    {
        $addresses = Address::query()->where('customer_id', $request->customer()->id)->with(self::RELATIONS)
            ->orderByDesc('is_default_shipping')->orderByDesc('id')->get();

        return StorefrontAddressResource::collection($addresses);
    }

    /** `POST /account/addresses`. The first address becomes the default. */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    #[OpenApiResponse(422, 'A field is invalid, or the book is full (`validation-error`)')]
    public function store(AddressRequest $request, SaveCustomerAddress $save): JsonResponse
    {
        $address = $save->handle($request->customer(), $request->toData());

        return (new StorefrontAddressResource($address->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    /** `PATCH /account/addresses/{address}` — replace the address fields. */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    #[OpenApiResponse(404, 'Not one of the customer\'s addresses (`not-found`)')]
    #[OpenApiResponse(422, 'A field is invalid (`validation-error`)')]
    public function update(AddressRequest $request, int $address, SaveCustomerAddress $save): StorefrontAddressResource
    {
        $customer = $request->customer();
        $saved = $save->handle($customer, $request->toData(), self::own($customer, $address));

        return new StorefrontAddressResource($saved->load(self::RELATIONS));
    }

    /** `POST /account/addresses/{address}/default`. */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    #[OpenApiResponse(404, 'Not one of the customer\'s addresses (`not-found`)')]
    public function makeDefault(AccountReadRequest $request, int $address, SetDefaultAddress $setDefault): StorefrontAddressResource
    {
        return new StorefrontAddressResource($setDefault->handle(self::own($request->customer(), $address))->load(self::RELATIONS));
    }

    /** `DELETE /account/addresses/{address}`. Placed orders keep their own copy. */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    #[OpenApiResponse(404, 'Not one of the customer\'s addresses (`not-found`)')]
    public function destroy(AccountReadRequest $request, int $address, DeleteCustomerAddress $delete): Response
    {
        $delete->handle(self::own($request->customer(), $address));

        return response()->noContent();
    }

    private static function own(Customer $customer, int $id): Address
    {
        return Address::query()->where('customer_id', $customer->id)->whereKey($id)->first() ?? throw new NotFoundHttpException;
    }
}
