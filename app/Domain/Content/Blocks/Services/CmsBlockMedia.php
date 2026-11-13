<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Services;

use App\Domain\Content\Blocks\Enums\BlockPlacement;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use Illuminate\Support\Facades\DB;

/**
 * Registers the images CMS blocks use as media usages (`cms_block_media`): the draft rows on
 * every draft write, the live rows on publish / revert. Called by the block Actions inside their
 * own transaction — never on its own. A media id that no longer exists is skipped (a reverted
 * version may name an image deleted since), so a sync never fails a save.
 */
final class CmsBlockMedia
{
    public const string DRAFT = 'draft';

    public const string LIVE = 'live';

    /** Rewrite the placement's draft usages from its cms_blocks rows. */
    public static function syncDraft(BlockPlacement $placement): void
    {
        $blocks = CmsBlock::query()->inPlacement($placement)->get()
            ->map(static fn (CmsBlock $block): array => $block->snapshot())->values()->all();

        self::sync($placement, self::DRAFT, $blocks);
    }

    /** Rewrite the placement's live usages from its newest published version (none: no live usages). */
    public static function syncLive(BlockPlacement $placement): void
    {
        $live = CmsLayoutVersion::query()->ofPlacement($placement)->orderByDesc('version')->first();

        self::sync($placement, self::LIVE, $live === null ? [] : $live->blocks);
    }

    /**
     * Media ids in one block's content: `content.media_id` and `content.slides[].media_id`.
     *
     * @param  array<string, mixed>  $content
     * @return list<int>
     */
    public static function mediaIds(array $content): array
    {
        $ids = [];
        if (is_numeric($content['media_id'] ?? null)) {
            $ids[] = (int) $content['media_id'];
        }
        foreach (is_array($content['slides'] ?? null) ? $content['slides'] : [] as $slide) {
            if (is_array($slide) && is_numeric($slide['media_id'] ?? null)) {
                $ids[] = (int) $slide['media_id'];
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /** @param  list<array<string, mixed>>  $blocks  block snapshots */
    private static function sync(BlockPlacement $placement, string $layer, array $blocks): void
    {
        $rows = [];
        foreach ($blocks as $block) {
            $content = is_array($block['content'] ?? null) ? $block['content'] : [];
            foreach (self::mediaIds($content) as $mediaId) {
                $rows[$mediaId.'|'.$block['uuid']] = [
                    'media_id' => $mediaId,
                    'block_uuid' => (string) $block['uuid'],
                    'block_type' => (string) $block['type'],
                    'block_label' => is_string($block['label'] ?? null) ? mb_substr($block['label'], 0, 191) : null,
                ];
            }
        }

        $existing = $rows === [] ? [] : array_flip(array_map('intval', DB::table('media')
            ->whereIn('id', array_unique(array_column($rows, 'media_id')))->pluck('id')->all()));
        $now = now();

        DB::table('cms_block_media')->where('placement', $placement->value)->where('layer', $layer)->delete();

        $insert = [];
        foreach ($rows as $row) {
            if (isset($existing[$row['media_id']])) {
                $insert[] = [...$row, 'placement' => $placement->value, 'layer' => $layer, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        if ($insert !== []) {
            DB::table('cms_block_media')->insert($insert);
        }
    }
}
