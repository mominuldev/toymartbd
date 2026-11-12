<?php

declare(strict_types=1);

/*
 * Trusted proxies (read by Laravel's TrustProxies middleware, which runs on every request).
 *
 * Every storefront request reaches Laravel from the Next.js server, so without this every
 * per-IP rate limit (OTP, search-suggest, tracking, reviews) would be keyed on that one
 * server's address. The Next server forwards the shopper's IP as X-Forwarded-For
 * (toymartbd-app/storefront/lib/api.ts) and Laravel honours it ONLY when the TCP peer is
 * one of the addresses listed here: a comma-separated list of IPs or CIDR ranges, e.g.
 * `TRUSTED_PROXIES=10.0.0.12,10.0.1.0/24`.
 *
 * The default is NONE. A wildcard (`*` / `**`) is refused on purpose: trusting every
 * caller would let any client choose its own rate-limit bucket by sending the header.
 * An empty array (never null) also stops the framework's Laravel Cloud / Forge
 * auto-trust of `*` from applying.
 */

$proxies = array_values(array_filter(
    array_map(trim(...), explode(',', (string) env('TRUSTED_PROXIES', ''))),
    static fn (string $proxy): bool => $proxy !== '' && $proxy !== '*' && $proxy !== '**',
));

return [
    'proxies' => $proxies,
];
