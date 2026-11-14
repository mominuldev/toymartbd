<?php

declare(strict_types=1);

namespace App\Domain\Content\Listeners;

use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Fulfilment\Events\DeliveryReferenceDataChanged;

/**
 * DeliveryReferenceDataChanged → purge `delivery`: the published rate table, the district and
 * upazila selectors (their `upazila_required` / `serviceable` flags come from the same rules).
 * The per-request delivery quote is never cached. Tag vocabulary: docs/api/revalidation.md.
 */
final class RevalidateDeliveryData
{
    public const string TAG = 'delivery';

    public function handle(DeliveryReferenceDataChanged $event): void
    {
        RevalidateStorefrontTags::dispatch([self::TAG]);
    }
}
