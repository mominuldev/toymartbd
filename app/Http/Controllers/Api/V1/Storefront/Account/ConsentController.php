<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront\Account;

use App\Domain\Notification\Actions\SetMessageConsent;
use App\Domain\Notification\Enums\MessageConsentPurpose;
use App\Domain\Notification\Models\MessageConsent;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\Account\AccountReadRequest;
use App\Http\Requests\Storefront\Account\UpdateConsentRequest;
use App\Http\Resources\Storefront\Account\AccountConsentResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

/**
 * The signed-in customer's own message consents (P8): today birthday messages only. There is
 * no customer id in the path — the token is the customer, so nobody can read or change
 * another customer's consent. Never asked on checkout (the profile page only).
 */
final class ConsentController extends ApiController
{
    /** `GET /account/consents` — the customer's own; no row reads as not consented. */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    public function show(AccountReadRequest $request): AccountConsentResource
    {
        return new AccountConsentResource(self::birthday($request->customer()->id));
    }

    /** `PUT /account/consents` — opt in or out; recorded with source `storefront`, a withdrawal is kept, not deleted. */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    #[OpenApiResponse(422, 'A field is invalid (`validation-error`)')]
    public function update(UpdateConsentRequest $request, SetMessageConsent $set): AccountConsentResource
    {
        $id = $request->customer()->id;
        $set->handle($id, MessageConsentPurpose::Birthday, $request->birthday(), 'storefront', null);

        return new AccountConsentResource(self::birthday($id));
    }

    private static function birthday(int $customerId): ?MessageConsent
    {
        return MessageConsent::query()->where('customer_id', $customerId)->where('purpose', MessageConsentPurpose::Birthday->value)->first();
    }
}
