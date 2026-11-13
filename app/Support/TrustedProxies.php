<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Parses the TRUSTED_PROXIES value (config/trustedproxy.php): a comma-separated list of
 * IPs or CIDR ranges. Blank entries and wildcards (`*`, `**`) are dropped on purpose —
 * trusting every caller would let any client pick its own rate-limit bucket.
 */
final class TrustedProxies
{
    /** @return list<string> */
    public static function parse(string $value): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn (string $proxy): bool => $proxy !== '' && $proxy !== '*' && $proxy !== '**',
        ));
    }
}
