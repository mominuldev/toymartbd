<?php

declare(strict_types=1);

namespace App\Domain\Tracking\DTOs;

/**
 * The shopper's browser context as the storefront server forwarded it: real client IP
 * and user agent (the Next.js server calls the API, so the request's own IP is not the
 * shopper's), GA client/session ids and Meta's `_fbp` / `_fbc` cookies.
 *
 * Personal data: carried only in the dispatch job's payload and `order_tracking_contexts`,
 * never logged and never written to `tracking_events`.
 */
final readonly class TrackingClientContext
{
    public function __construct(
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $gaClientId = null,
        public ?string $gaSessionId = null,
        public ?string $fbp = null,
        public ?string $fbc = null,
    ) {}

    /** @param  array<string, mixed>  $client  the request's `client` object */
    public static function fromArray(array $client): self
    {
        $s = static fn (string $key): ?string => isset($client[$key]) && is_scalar($client[$key]) && trim((string) $client[$key]) !== ''
            ? trim((string) $client[$key])
            : null;

        return new self($s('ip'), $s('user_agent'), $s('ga_client_id'), $s('ga_session_id'), $s('fbp'), $s('fbc'));
    }
}
