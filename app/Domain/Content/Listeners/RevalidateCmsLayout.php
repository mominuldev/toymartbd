<?php

declare(strict_types=1);

namespace App\Domain\Content\Listeners;

use App\Domain\Content\Blocks\Services\LayoutCacheTags;
use App\Domain\Content\Blocks\ValueObjects\LayoutKey;
use App\Domain\Content\Events\CmsLayoutPublished;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;

/**
 * CmsLayoutPublished → drop the layout's storefront tags (`content:home`, or a page's
 * `content:pages` + `content:page:{slug}` — LayoutCacheTags). The job is
 * queued after commit (rule 4). Schedule boundaries are covered by
 * `toymart:content:revalidate-scheduled` (the one scheduled-content sweep), which sends the same tag.
 */
final class RevalidateCmsLayout
{
    public function __construct(private readonly LayoutCacheTags $tags) {}

    public function handle(CmsLayoutPublished $event): void
    {
        $tags = $this->tags->for(LayoutKey::fromString($event->placement));
        if ($tags !== []) {
            RevalidateStorefrontTags::dispatch($tags);
        }
    }
}
