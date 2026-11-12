<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront\Account;

use App\Domain\Cart\Actions\MergeGuestCart;
use App\Domain\Customer\Actions\RequestLoginOtp;
use App\Domain\Customer\Actions\VerifyLoginOtp;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\Account\AccountReadRequest;
use App\Http\Requests\Storefront\Account\RequestOtpRequest;
use App\Http\Requests\Storefront\Account\VerifyOtpRequest;
use App\Http\Resources\Storefront\Account\AccountSessionResource;
use App\Http\Resources\Storefront\Account\OtpIssuedResource;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;

/**
 * Customer sign-in by phone + SMS code (P4-STO-11, docs/api/account.md). Called by the
 * Next.js server: `otp` and `otp/verify` with its storefront token; `account/logout` with
 * the customer's own token. The customer token goes back to the Next.js server only, which
 * keeps it in an httpOnly cookie — it never reaches browser JavaScript (rule 28).
 */
final class AuthController extends ApiController
{
    /**
     * `POST /auth/otp` — text a 6-digit code. Same answer whether or not the phone has an
     * account. SMS only, never WhatsApp (rule 32b).
     */
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(422, 'Not a valid 013–019 mobile number (`validation-error`)')]
    #[OpenApiResponse(429, 'A code was sent under a minute ago, five this hour, or three in 10 minutes (`too-many-requests`, `Retry-After`)')]
    public function requestOtp(RequestOtpRequest $request, RequestLoginOtp $requestOtp): JsonResponse
    {
        $issued = $requestOtp->handle($request->phone(), $request->ip(), App::getLocale());

        return (new OtpIssuedResource($issued))->response()->setStatusCode(202);
    }

    /**
     * `POST /auth/otp/verify` — check the code; on success return the customer's token and
     * merge the guest cart named by `X-Cart-Token` into the customer's cart.
     */
    #[HeaderParameter('X-Cart-Token', 'The guest cart to merge into the customer\'s cart.', type: 'string')]
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability, or the customer is blocked (`forbidden`)')]
    #[OpenApiResponse(422, 'Wrong, expired or used code — the same `code` error for each (`validation-error`)')]
    #[OpenApiResponse(429, 'Too many verification attempts for this phone')]
    public function verify(VerifyOtpRequest $request, VerifyLoginOtp $verify, MergeGuestCart $merge): JsonResponse
    {
        $session = $verify->handle($request->phone(), $request->code(), App::getLocale());
        $cart = $merge->handle($request->cartToken(), $session->customer->id);

        return (new AccountSessionResource($session, $cart))->response()->setStatusCode(200);
    }

    /** `POST /account/logout` — revoke the token this request carries. */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    public function logout(AccountReadRequest $request): Response
    {
        $request->customer()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
