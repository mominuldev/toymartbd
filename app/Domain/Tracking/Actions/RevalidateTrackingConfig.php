<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Actions;

use App\Domain\Content\Jobs\RevalidateStorefrontTags;

/**
 * Drops the storefront's cached `GET /storefront/tracking/config` (cache tag
 * `tracking-config`, docs/api/revalidation.md) so new ids, flags and consent mode reach the
 * next page render. Queued, after commit (rule 4). Called by UpdateTrackingSettings; the
 * admin controller may call it directly after any other change to the public config.
 */
final readonly class RevalidateTrackingConfig
{
    public const string TAG = 'tracking-config';

    public function handle(): void
    {
        RevalidateStorefrontTags::dispatch([self::TAG]);
    }
}
