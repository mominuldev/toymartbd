<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront\Account;

use App\Domain\Cart\Actions\MergeGuestCart;
use App\Domain\Customer\Actions\RegisterCustomer;
use App\Domain\Customer\Actions\RequestLoginOtp;
use App\Domain\Customer\Actions\ResetCustomerPassword;
use App\Domain\Customer\Actions\SignInWithPassword;
use App\Domain\Customer\Actions\StartCustomerRegistration;
use App\Domain\Customer\Actions\VerifyLoginOtp;
use App\Domain\Customer\DTOs\CustomerSession;
use App\Domain\Customer\Services\OtpCodes;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\Account\AccountReadRequest;
use App\Http\Requests\Storefront\Account\CompleteRegistrationRequest;
use App\Http\Requests\Storefront\Account\PasswordSignInRequest;
use App\Http\Requests\Storefront\Account\RequestOtpRequest;
use App\Http\Requests\Storefront\Account\ResetPasswordRequest;
use App\Http\Requests\Storefront\Account\VerifyOtpRequest;
use App\Http\Resources\Storefront\Account\AccountSessionResource;
use App\Http\Resources\Storefront\Account\OtpIssuedResource;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;

/**
 * Customer sign-in (P4-STO-11, docs/api/account.md): phone + SMS code, and since the owner's
 * decision of 2026-11-20 phone + password, "Create an account" (code + name + password) and
 * "Forgot password" (code + new password). Called by the Next.js server with its storefront
 * token; `account/logout` with the customer's own token. The customer token goes back to the
 * Next.js server only, which keeps it in an httpOnly cookie — never browser JavaScript (rule 28).
 * Every code goes by SMS, never WhatsApp (rule 32b).
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

    /**
     * `POST /auth/password` — sign in with phone + password; merges the guest cart named by
     * `X-Cart-Token`. One `password` error for an unknown phone, no password set, or a wrong one.
     */
    #[HeaderParameter('X-Cart-Token', 'The guest cart to merge into the customer\'s cart.', type: 'string')]
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability, or the customer is blocked (`forbidden`)')]
    #[OpenApiResponse(422, 'Unknown phone, no password set, or a wrong password — the same `password` error for each (`validation-error`)')]
    #[OpenApiResponse(429, 'Too many password attempts for this phone or from this IP (`too-many-requests`)')]
    public function passwordSignIn(PasswordSignInRequest $request, SignInWithPassword $signIn, MergeGuestCart $merge): JsonResponse
    {
        return $this->session($signIn->handle($request->phone(), $request->password()), $request->cartToken(), $merge);
    }

    /**
     * `POST /auth/register` — "Create an account", step 1: text a `register` code, or answer 409
     * `phone-already-registered` when the phone already has an account (a guest-checkout record
     * does not count). Limited per phone and per IP like `otp`.
     */
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(409, 'The phone already has an account (`phone-already-registered`)')]
    #[OpenApiResponse(422, 'Not a valid 013–019 mobile number (`validation-error`)')]
    #[OpenApiResponse(429, 'A code was sent under a minute ago, five this hour, or three in 10 minutes (`too-many-requests`, `Retry-After`)')]
    public function startRegistration(RequestOtpRequest $request, StartCustomerRegistration $start): JsonResponse
    {
        $issued = $start->handle($request->phone(), $request->ip(), App::getLocale());

        return (new OtpIssuedResource($issued))->response()->setStatusCode(202);
    }

    /**
     * `POST /auth/register/verify` — "Create an account", step 2: check the `register` code, make
     * the account with the name and password (a guest-checkout record keeps its own name), sign in
     * and merge the guest cart named by `X-Cart-Token`.
     */
    #[HeaderParameter('X-Cart-Token', 'The guest cart to merge into the customer\'s cart.', type: 'string')]
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability, or the customer is blocked (`forbidden`)')]
    #[OpenApiResponse(409, 'The phone became registered since the code was sent (`phone-already-registered`)')]
    #[OpenApiResponse(422, 'Wrong, expired or used code, or an invalid name or password (`validation-error`)')]
    #[OpenApiResponse(429, 'Too many verification attempts for this phone')]
    public function completeRegistration(CompleteRegistrationRequest $request, RegisterCustomer $register, MergeGuestCart $merge): JsonResponse
    {
        $session = $register->handle($request->phone(), $request->code(), $request->name(), $request->password(), App::getLocale());

        return $this->session($session, $request->cartToken(), $merge);
    }

    /**
     * `POST /auth/password/forgot` — text a `password_reset` code. Same answer whether or not the
     * phone has an account.
     */
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(422, 'Not a valid 013–019 mobile number (`validation-error`)')]
    #[OpenApiResponse(429, 'A code was sent under a minute ago, five this hour, or three in 10 minutes (`too-many-requests`, `Retry-After`)')]
    public function requestPasswordReset(RequestOtpRequest $request, RequestLoginOtp $requestOtp): JsonResponse
    {
        $issued = $requestOtp->handle($request->phone(), $request->ip(), App::getLocale(), OtpCodes::PURPOSE_PASSWORD_RESET);

        return (new OtpIssuedResource($issued))->response()->setStatusCode(202);
    }

    /**
     * `POST /auth/password/reset` — check the `password_reset` code, set the new password, sign
     * out every other session, sign in and merge the guest cart named by `X-Cart-Token`.
     */
    #[HeaderParameter('X-Cart-Token', 'The guest cart to merge into the customer\'s cart.', type: 'string')]
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability, or the customer is blocked (`forbidden`)')]
    #[OpenApiResponse(422, 'Wrong, expired or used code, or an invalid password (`validation-error`)')]
    #[OpenApiResponse(429, 'Too many verification attempts for this phone')]
    public function resetPassword(ResetPasswordRequest $request, ResetCustomerPassword $reset, MergeGuestCart $merge): JsonResponse
    {
        $session = $reset->handle($request->phone(), $request->code(), $request->password(), App::getLocale());

        return $this->session($session, $request->cartToken(), $merge);
    }

    /** `POST /account/logout` — revoke the token this request carries. */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    public function logout(AccountReadRequest $request): Response
    {
        $request->customer()->currentAccessToken()->delete();

        return response()->noContent();
    }

    private function session(CustomerSession $session, ?string $cartToken, MergeGuestCart $merge): JsonResponse
    {
        $cart = $merge->handle($cartToken, $session->customer->id);

        return (new AccountSessionResource($session, $cart))->response()->setStatusCode(200);
    }
}
