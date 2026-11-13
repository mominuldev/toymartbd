<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Actions;

use App\Domain\Content\Blocks\DTOs\BlockSettings;
use App\Domain\Content\Blocks\Enums\BlockPlacement;
use App\Domain\Content\Blocks\Enums\BlockType;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Content\Blocks\Services\CmsBlockMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds a block to a placement's draft at `$position` (0-based; null = the end), shifting the
 * blocks after it. Content defaults to the type's schema defaults. Nothing goes live until publish.
 */
final class CreateBlock
{
    public function handle(BlockPlacement $placement, BlockType $type, BlockSettings $settings, ?int $position, ?int $userId): CmsBlock
    {
        return DB::transaction(static function () use ($placement, $type, $settings, $position, $userId): CmsBlock {
            $blocks = CmsBlock::query()->inPlacement($placement)->lockForUpdate()->get();
            $at = $position === null ? $blocks->count() : max(0, min($position, $blocks->count()));

            foreach ($blocks->values() as $index => $existing) {
                $target = $index >= $at ? $index + 1 : $index;
                if ($existing->position !== $target) {
                    $existing->forceFill(['position' => $target])->save();
                }
            }

            $schema = $type->schema();
            $attributes = $settings->attributes();
            $attributes['content'] = $settings->content ?? $schema->normalize($schema->defaults());

            $block = new CmsBlock([
                ...$attributes,
                'uuid' => (string) Str::uuid(),
                'placement' => $placement,
                'type' => $type,
                'position' => $at,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
            $block->save();
            CmsBlockMedia::syncDraft($placement); // Media Library "where used"

            return $block->refresh();
        });
    }
}
