<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The daily low-stock digest for one Dhaka date was compiled (InventoryDigest), ready to
 * send to the managers (Notification's MailInventoryAlerts). Plain data only.
 *
 * `digest` shape: see InventoryDigest::compile().
 */
final readonly class InventoryDigestCompiled
{
    use Dispatchable;

    /** @param  array<string, mixed>  $digest */
    public function __construct(
        public string $date,
        public array $digest,
    ) {}
}
