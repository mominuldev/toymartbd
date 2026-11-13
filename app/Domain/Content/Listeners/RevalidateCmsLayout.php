<?php

declare(strict_types=1);

namespace App\Domain\Content\Listeners;

use App\Domain\Content\Events\CmsLayoutPublished;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;

/**
 * CmsLayoutPublished → drop the placement's storefront tag (`content:home`). The job is
 * queued after commit (rule 4). Schedule boundaries are covered by
 * `toymart:content:revalidate-schedule`, which sends the same tag.
 */
final class RevalidateCmsLayout
{
    public function handle(CmsLayoutPublished $event): void
    {
        RevalidateStorefrontTags::dispatch(['content:'.$event->placement]);
    }
}
