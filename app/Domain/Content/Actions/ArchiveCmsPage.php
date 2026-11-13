<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Enums\PageStatus;
use App\Domain\Content\Events\CmsPageChanged;
use App\Domain\Content\Models\CmsPage;
use Illuminate\Support\Facades\DB;

/**
 * Archive a static page: it leaves the storefront (404) and menus stop linking to it. Pages are
 * archived, not deleted, so their slug history and SEO stay attached. Idempotent.
 */
final class ArchiveCmsPage
{
    public function handle(CmsPage $page, ?int $actorId = null): CmsPage
    {
        return DB::transaction(function () use ($page, $actorId): CmsPage {
            if ($page->status === PageStatus::Archived) {
                return $page;
            }

            $page->status = PageStatus::Archived;
            $page->updated_by = $actorId;
            $page->save();

            CmsPageChanged::dispatch($page->id, $page->slug);

            return $page;
        });
    }
}
