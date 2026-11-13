<?php

declare(strict_types=1);

namespace App\Domain\Risk\Enums;

/**
 * `customer_risk_profiles.status` — a STAFF decision about a phone, never set by scoring.
 *
 *  - `normal`    no decision;
 *  - `watch`     every order from this phone goes to the verification queue (`watchlist`);
 *  - `blacklist` every order goes to the queue (`blacklisted`) — held, never auto-cancelled
 *                (rules 23, 24b): a person decides, with the history in front of them;
 *  - `whitelist` a known good customer: a high score alone no longer holds the order (the
 *                first-order rule, 33b, and the COD-advance rule still apply).
 */
enum RiskProfileStatus: string
{
    case Normal = 'normal';
    case Watch = 'watch';
    case Blacklist = 'blacklist';
    case Whitelist = 'whitelist';

    /** The hold reason this status adds to every order from the phone, if any. */
    public function holdReason(): ?string
    {
        return match ($this) {
            self::Watch => 'watchlist',
            self::Blacklist => 'blacklisted',
            default => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
