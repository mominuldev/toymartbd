<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Actions;

use App\Domain\Content\Blocks\Enums\BlockPlacement;
use App\Domain\Content\Blocks\Exceptions\NothingToPublish;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Domain\Content\Blocks\Services\HomeLayoutCache;
use App\Domain\Content\Events\CmsLayoutPublished;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Publishes the draft: freezes it into the next immutable version, which becomes live, then
 * CmsLayoutPublished revalidates the storefront (after commit). A draft identical to the live
 * version is refused (NothingToPublish): history holds only real changes.
 */
final class PublishLayout
{
    public function __construct(private readonly HomeLayoutCache $cache) {}

    public function handle(BlockPlacement $placement, ?int $userId, ?string $note = null): CmsLayoutVersion
    {
        $version = DB::transaction(static function () use ($placement, $userId, $note): CmsLayoutVersion {
            // Serialise publishers of one placement: the draft rows are the lock.
            $blocks = CmsBlock::query()->inPlacement($placement)->lockForUpdate()->get();
            $snapshot = array_values($blocks->map(static fn (CmsBlock $block): array => $block->snapshot())->all());
            $hash = CmsLayoutVersion::hash($snapshot);

            $live = CmsLayoutVersion::query()->ofPlacement($placement)->orderByDesc('version')->lockForUpdate()->first();
            if (($live === null && $snapshot === []) || $live?->content_hash === $hash) {
                throw new NothingToPublish;
            }

            $version = CmsLayoutVersion::query()->create([
                'placement' => $placement,
                'version' => ($live->version ?? 0) + 1,
                'blocks' => $snapshot,
                'block_count' => count($snapshot),
                'content_hash' => $hash,
                'restored_from_version' => null,
                'note' => $note,
                'published_by' => $userId,
                'published_at' => CarbonImmutable::now('UTC'),
            ]);

            CmsLayoutPublished::dispatch($placement->value, $version->version);

            return $version;
        });

        $this->cache->forget($placement);

        return $version;
    }
}
