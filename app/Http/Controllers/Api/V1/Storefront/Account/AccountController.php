<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront\Account;

use App\Domain\Customer\Actions\ChangeCustomerPassword;
use App\Domain\Customer\Actions\UpdateCustomerProfile;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\Account\AccountReadRequest;
use App\Http\Requests\Storefront\Account\UpdatePasswordRequest;
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

    /** `PATCH /account` — name, email, language, date of birth. The phone is the identity and not editable here. */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    #[OpenApiResponse(422, 'A field is invalid (`validation-error`)')]
    public function update(UpdateProfileRequest $request, UpdateCustomerProfile $update): AccountResource
    {
        return new AccountResource($update->handle($request->customer(), $request->changes()));
    }

    /**
     * `PUT /account/password` — set the first password (required after an SMS sign-in) or change
     * it (needs `current_password`). Signs out every other session; this one stays signed in.
     */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    #[OpenApiResponse(422, 'The current password is wrong or missing, or the new one is invalid (`validation-error`)')]
    #[OpenApiResponse(429, 'Too many password changes for this account (`too-many-requests`)')]
    public function updatePassword(UpdatePasswordRequest $request, ChangeCustomerPassword $change): AccountResource
    {
        $customer = $request->customer();
        // The customer guard is bearer-token only (Sanctum), so this is the PersonalAccessToken in use.
        $keep = (int) $customer->currentAccessToken()->getKey();

        return new AccountResource($change->handle($customer, $request->currentPassword(), $request->password(), $keep));
    }
}
