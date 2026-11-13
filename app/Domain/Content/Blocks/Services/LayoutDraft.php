<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Services;

use App\Domain\Content\Blocks\Enums\BlockPlacement;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use Illuminate\Database\Eloquent\Collection;

/** Read side of a placement's draft: its ordered blocks, its snapshot and whether it differs from live. */
final class LayoutDraft
{
    /** @return Collection<int, CmsBlock> */
    public function blocks(BlockPlacement $placement): Collection
    {
        return CmsBlock::query()->inPlacement($placement)->get();
    }

    /** @return list<array<string, mixed>> */
    public function snapshot(BlockPlacement $placement): array
    {
        return array_values($this->blocks($placement)->map(static fn (CmsBlock $block): array => $block->snapshot())->all());
    }

    public function hasUnpublishedChanges(BlockPlacement $placement, ?CmsLayoutVersion $live = null): bool
    {
        $live ??= CmsLayoutVersion::latestFor($placement);
        $snapshot = $this->snapshot($placement);

        if ($live === null) {
            return $snapshot !== [];
        }

        return CmsLayoutVersion::hash($snapshot) !== $live->content_hash;
    }
}
