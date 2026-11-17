<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\DTOs;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The answer to "do these courier credentials work?". `ok` means the courier accepted them.
 *
 * Reasons: `connected` · `no_credentials` (nothing to try) · `credentials_rejected` (the
 * courier said 401/403 — never retried, Steadfast locks a key after ten failures) ·
 * `unreachable` (timeout, 5xx, garbage — try later) · `store_missing` / `store_not_found`
 * (Pathao accepted the keys but the pickup store id is unset or not one of the account's stores).
 */
final readonly class ConnectionCheck
{
    public function __construct(
        public bool $ok,
        public string $reason,
        public CarbonImmutable $checkedAt,
        public ?Money $balance = null,
        public ?string $storeName = null,
        public bool $sandbox = false,
    ) {}

    public static function failed(string $reason, bool $sandbox = false): self
    {
        return new self(false, $reason, CarbonImmutable::now(), sandbox: $sandbox);
    }
}
