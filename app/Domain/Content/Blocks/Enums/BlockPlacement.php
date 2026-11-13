<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Enums;

/** Where a block list renders. Only the homepage is built (Phase 7); category and product placements may follow. */
enum BlockPlacement: string
{
    case Home = 'home';

    /** Storefront cache tag dropped when this placement's live version changes (docs/api/revalidation.md). */
    public function cacheTag(): string
    {
        return 'content:'.$this->value;
    }

    /** PreviewToken type for a draft preview of this placement. */
    public function previewTokenType(): string
    {
        return $this->value.'_draft';
    }
}
