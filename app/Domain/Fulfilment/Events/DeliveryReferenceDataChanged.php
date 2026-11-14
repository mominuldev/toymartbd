<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something the storefront's delivery reference data is computed from changed: a courier's
 * rate card, which courier is on or the default for quotes, or a `delivery.*` setting such as
 * the free-delivery threshold. `GET /storefront/delivery-rates`, `/districts` and
 * `/districts/{id}/upazilas` are cached under the `delivery` tag (docs/api/revalidation.md).
 *
 * Dispatched from inside the Action's transaction but held until it commits
 * (ShouldDispatchAfterCommit), so a rolled back write never purges anything.
 * `reason` is for logs and tests only (`rate_card`, `provider_status`, `free_shipping_threshold`, `delivery_areas`).
 */
final readonly class DeliveryReferenceDataChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public string $reason) {}
}
