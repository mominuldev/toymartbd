<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Services;

use App\Domain\Content\Blocks\ValueObjects\LayoutKey;
use App\Domain\Content\Models\CmsPage;

/**
 * The storefront cache tags a layout renders under (docs/api/revalidation.md): `content:home`
 * for the homepage; `content:pages` + `content:page:{slug}` for a static page (the same pair a
 * page save sends). A page that no longer exists has nothing cached to drop.
 */
final class LayoutCacheTags
{
    /** @return list<string> */
    public function for(LayoutKey $key): array
    {
        if ($key->isHome()) {
            return ['content:home'];
        }

        $slug = CmsPage::query()->whereKey($key->pageId)->value('slug');

        return is_string($slug) ? ['content:pages', 'content:page:'.$slug] : [];
    }
}
