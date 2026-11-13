<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Actions;

use App\Domain\Content\Blocks\Models\CmsBlock;
use Illuminate\Support\Facades\DB;

/** Removes a block from the draft and closes the gap. Published versions keep their copy. */
final class DeleteBlock
{
    public function handle(CmsBlock $block): void
    {
        DB::transaction(static function () use ($block): void {
            $placement = $block->placement;
            $block->delete();

            foreach (CmsBlock::query()->inPlacement($placement)->lockForUpdate()->get()->values() as $index => $rest) {
                if ($rest->position !== $index) {
                    $rest->forceFill(['position' => $index])->save();
                }
            }
        });
    }
}
