<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/**
 * Laravel skips CSRF verification while running unit tests. This subclass does
 * not, so a test can prove the admin API really enforces it. Swap it in with
 * `config(['sanctum.middleware.validate_csrf_token' => EnforcedCsrf::class])`.
 */
final class EnforcedCsrf extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
