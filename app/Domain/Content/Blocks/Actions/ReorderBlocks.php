<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Actions;

use App\Domain\Content\Blocks\Enums\BlockPlacement;
use App\Domain\Content\Blocks\Exceptions\InvalidBlockOrder;
use App\Domain\Content\Blocks\Models\CmsBlock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Sets the draft order. `$ids` must be a permutation of the placement's block ids. */
final class ReorderBlocks
{
    /**
     * @param  list<int>  $ids
     * @return Collection<int, CmsBlock>
     */
    public function handle(BlockPlacement $placement, array $ids, ?int $userId): Collection
    {
        return DB::transaction(static function () use ($placement, $ids, $userId): Collection {
            $blocks = CmsBlock::query()->inPlacement($placement)->lockForUpdate()->get()->keyBy('id');

            $given = $ids;
            sort($given);
            $existing = $blocks->keys()->map(intval(...))->sort()->values()->all();
            if ($given !== $existing) {
                throw new InvalidBlockOrder;
            }

            foreach ($ids as $position => $id) {
                $block = $blocks[$id];
                if ($block->position !== $position) {
                    $block->forceFill(['position' => $position, 'updated_by' => $userId])->save();
                }
            }

            return CmsBlock::query()->inPlacement($placement)->get();
        });
    }
}
