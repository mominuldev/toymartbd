<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Actions;

use App\Domain\Content\Blocks\DTOs\BlockSettings;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Content\Blocks\Services\CmsBlockMedia;
use Illuminate\Support\Facades\DB;

/** Edits a draft block: content (replaced whole), label, visibility, schedule. The type never changes. */
final class UpdateBlock
{
    public function handle(CmsBlock $block, BlockSettings $settings, ?int $userId): CmsBlock
    {
        return DB::transaction(static function () use ($block, $settings, $userId): CmsBlock {
            $block->fill($settings->attributes());
            $block->updated_by = $userId;
            $block->save();
            CmsBlockMedia::syncDraft($block->placement); // Media Library "where used"

            return $block->refresh();
        });
    }
}
