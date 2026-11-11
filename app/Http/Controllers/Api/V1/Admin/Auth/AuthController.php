<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Auth\LoginRequest;
use App\Http\Resources\Admin\AuthUserResource;
use App\Models\User;
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
    /**
     * Wrong email, wrong password and a deactivated account all get the same 422
     * on `email`, so the response never reveals whether an account exists or its
     * state. Throttled by the `login` limiter (5/min per IP).
     */
    public function login(LoginRequest $request): AuthUserResource
    {
        if (! $request->hasSession()) {
            // Only requests from a SANCTUM_STATEFUL_DOMAINS origin get a session.
            throw new BadRequestHttpException('Sign-in requires a first-party browser session (send Origin or Referer).');
        }

        $guard = Auth::guard('web');

        $authenticated = $guard->attemptWhen(
            $request->credentials(),
            static fn (mixed $user): bool => $user instanceof User && $user->is_active,
            $request->remember(),
        );

        $user = $guard->user();

        if (! $authenticated || ! $user instanceof User) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

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
