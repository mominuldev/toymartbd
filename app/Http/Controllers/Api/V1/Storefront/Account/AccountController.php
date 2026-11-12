<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront\Account;

use App\Domain\Customer\Actions\UpdateCustomerProfile;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\Account\AccountReadRequest;
use App\Http\Requests\Storefront\Account\UpdateProfileRequest;
use App\Http\Resources\Storefront\Account\AccountResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

/** The signed-in customer's profile (P4-STO-11). */
final class AccountController extends ApiController
{
    /** `GET /account` — profile plus the counts for the account home. */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    public function show(AccountReadRequest $request): AccountResource
    {
        return new AccountResource($request->customer());
    }

    /** `PATCH /account` — name, email, language. The phone is the identity and not editable here. */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    #[OpenApiResponse(422, 'A field is invalid (`validation-error`)')]
    public function update(UpdateProfileRequest $request, UpdateCustomerProfile $update): AccountResource
    {
        return new AccountResource($update->handle($request->customer(), $request->changes()));
    }
}
