<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/storefront/account/*` (P4-STO-11): after `auth:customer`, refuse a blocked customer's
 * still-valid token with 401 `unauthenticated` and revoke it, so the storefront drops its
 * cookie. (A deleted customer's token already fails: the soft-deleted owner is not found.)
 */
final class EnsureActiveCustomer
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->user('customer');

        if (! $customer instanceof Customer) {
            throw new AuthenticationException(guards: ['customer']);
        }
        if ($customer->status === CustomerStatus::Blocked) {
            $customer->tokens()->delete();

            throw new AuthenticationException(guards: ['customer']);
        }

        return $next($request);
    }
}
