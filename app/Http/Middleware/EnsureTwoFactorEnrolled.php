<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Staff\Services\TwoFactor;
use App\Http\Problems\ProblemDetails;
use App\Http\Problems\ProblemType;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin API gate, after EnsureActiveStaffSession (owner decision 2026-11-14): someone who can
 * see money and has not set up two-factor gets a 403 `two-factor-required` on everything
 * except what they need to set it up — who am I, sign out, and the two-factor endpoints.
 * Evaluated per request from live permissions, so a role edit takes effect immediately.
 */
final class EnsureTwoFactorEnrolled
{
    /** Route names reachable before enrolment. */
    public const array ALLOWED = [
        'api.v1.admin.auth.me',
        'api.v1.admin.auth.logout',
        'api.v1.admin.auth.two-factor.show',
        'api.v1.admin.auth.two-factor.store',
        'api.v1.admin.auth.two-factor.confirm',
    ];

    public function __construct(private TwoFactor $twoFactor) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User
            && ! in_array($request->route()?->getName(), self::ALLOWED, true)
            && ! $user->hasTwoFactor()
            && $this->twoFactor->requiredFor($user)) {
            return ProblemDetails::make(ProblemType::TwoFactorRequired, (string) __('staff.two_factor_required'))->toResponse($request);
        }

        return $next($request);
    }
}
