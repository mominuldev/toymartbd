<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Admin API gate, run after `auth:sanctum` (P3-API-05, CLAUDE.md rule 25).
 *
 * - The admin API is cookie-session only. A request authenticated by a bearer
 *   personal access token (e.g. the storefront server token) is refused with a
 *   403 `forbidden`, whatever abilities the token carries.
 * - A staff account deactivated mid-session is signed out and gets a 401
 *   `unauthenticated`, so the SPA sends the user back to the sign-in screen.
 */
final class EnsureActiveStaffSession
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        // HasApiTokens types this as PersonalAccessToken, but Sanctum's guard sets a
        // TransientToken for a session-authenticated user, and plain `web` auth sets none.
        /** @var object|null $token */
        $token = $user->currentAccessToken();

        if ($token !== null && ! $token instanceof TransientToken) {
            throw new AccessDeniedHttpException('The admin API accepts a staff session only, not an API token.');
        }

        if (! $user->is_active) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            throw new AuthenticationException;
        }

        return $next($request);
    }
}
