<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Domain\Staff\Actions\VerifySecondFactor;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Auth\LoginRequest;
use App\Http\Requests\Admin\Auth\TwoFactorChallengeRequest;
use App\Http\Resources\Admin\AuthUserResource;
use App\Http\Resources\Admin\TwoFactorChallengeResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Admin SPA sign-in over a Sanctum cookie session (P3-API-05, CLAUDE.md rule 25).
 *
 * Flow: `GET /sanctum/csrf-cookie` → `POST /auth/login` with `X-XSRF-TOKEN` →
 * session cookie. No token is ever returned to JavaScript.
 */
final class AuthController extends ApiController
{
    /** Session key for a sign-in that passed the password and waits for its second factor. */
    public const string TWO_FACTOR_PENDING = 'admin.two_factor.pending';

    /**
     * Wrong email, wrong password and a deactivated account all get the same 422
     * on `email`, so the response never reveals whether an account exists or its
     * state. Throttled by the `login` limiter (5/min per IP, 10/15 min per account).
     *
     * With two-factor on, a correct password does NOT sign in: the answer is a 202
     * `{ two_factor: true }` and the session remembers who is half-way for a few minutes;
     * `POST /auth/two-factor-challenge` with a code completes it.
     */
    #[OpenApiResponse(202, 'Password accepted; a two-factor code is needed (`POST /auth/two-factor-challenge`)', type: 'TwoFactorChallengeResource')]
    public function login(LoginRequest $request): AuthUserResource|JsonResponse
    {
        if (! $request->hasSession()) {
            // Only requests from a SANCTUM_STATEFUL_DOMAINS origin get a session.
            throw new BadRequestHttpException('Sign-in requires a first-party browser session (send Origin or Referer).');
        }

        $guard = Auth::guard('web');
        $provider = $guard->getProvider();
        $user = $provider->retrieveByCredentials(['email' => $request->credentials()['email']]);

        if (! $user instanceof User || ! $user->is_active || ! $provider->validateCredentials($user, $request->credentials())) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->forget(self::TWO_FACTOR_PENDING);

        if ($user->hasTwoFactor()) {
            // Session fixation: the half-signed-in session gets a fresh id too.
            $request->session()->regenerate();
            $request->session()->put(self::TWO_FACTOR_PENDING, [
                'id' => (int) $user->getKey(),
                'remember' => $request->remember(),
                'expires_at' => now()->addMinutes((int) config('auth.two_factor.challenge_minutes', 5))->getTimestamp(),
            ]);

            return (new TwoFactorChallengeResource(true))->response()->setStatusCode(202);
        }

        return $this->complete($request, $user, $request->remember());
    }

    /**
     * `POST /auth/two-factor-challenge` — `{ code }` from the authenticator app, or
     * `{ recovery_code }`. Completes the sign-in started by `/auth/login`. A wrong code is a
     * 422 on the field; an expired or missing pending sign-in is a 422 on `code` telling the
     * person to start again. Throttled per session and per account.
     */
    public function twoFactorChallenge(TwoFactorChallengeRequest $request, VerifySecondFactor $verify): AuthUserResource
    {
        $pending = $request->session()->get(self::TWO_FACTOR_PENDING);
        $user = is_array($pending) && is_int($pending['id'] ?? null) && (int) ($pending['expires_at'] ?? 0) >= now()->getTimestamp()
            ? User::query()->find($pending['id'])
            : null;

        if (! $user instanceof User || ! $user->is_active) {
            $request->session()->forget(self::TWO_FACTOR_PENDING);
            throw ValidationException::withMessages(['code' => __('staff.two_factor_expired')]);
        }

        if (! $verify->handle($user, $request->code(), $request->recoveryCode())) {
            throw ValidationException::withMessages([$request->code() !== null ? 'code' : 'recovery_code' => __('staff.two_factor_invalid')]);
        }

        $request->session()->forget(self::TWO_FACTOR_PENDING);

        return $this->complete($request, $user, (bool) ($pending['remember'] ?? false));
    }

    private function complete(Request $request, User $user, bool $remember): AuthUserResource
    {
        Auth::guard('web')->login($user, $remember);

        // Session fixation: the pre-login session id never becomes an authenticated one.
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return new AuthUserResource($user);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): AuthUserResource
    {
        /** @var User $user guaranteed by auth:sanctum + EnsureActiveStaffSession */
        $user = $request->user();

        return new AuthUserResource($user);
    }
}
