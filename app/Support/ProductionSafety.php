<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Config\Repository;
use Psr\Log\LoggerInterface;

/**
 * Settings that must never be on (debug) or off (staff two-factor) in production, whatever the
 * .env says (P9 security review; owner decision 2026-11-14).
 *
 * APP_DEBUG=true in production turns every 5xx problem into an exception message, file and
 * line (ProblemDetails) — and an exception message can carry SQL with a phone number or an
 * address in it (rule 20). A deploy that forgets to flip it is forced off and shouts about it
 * at boot, instead of leaking quietly.
 */
final class ProductionSafety
{
    public static function enforce(bool $isProduction, Repository $config, LoggerInterface $log): void
    {
        if (! $isProduction) {
            return;
        }

        // Two-factor for anyone who can see money is an owner decision (2026-11-14), not a
        // deploy option: switching it off is for the test suites only.
        if ($config->get('auth.two_factor.enforce') !== true) {
            $config->set('auth.two_factor.enforce', true);
            $config->set('auth.two_factor.enforce_forced_on', true);
            $log->critical('TWO_FACTOR_ENFORCE was off in production; forced on. Remove it from the environment.');
        }

        if ($config->get('app.debug') !== true) {
            return;
        }

        $config->set('app.debug', false);
        // Remembered so `toymart:launch:check` still reports the wrong environment value.
        $config->set('app.debug_forced_off', true);
        $log->critical('APP_DEBUG was true in production; forced off. Set APP_DEBUG=false in the environment.');
    }
}
