<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Actions;

use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Domain\Content\Blocks\Services\HomeLayoutCache;
use App\Domain\Content\Events\CmsLayoutPublished;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reverts to an earlier version: the draft is replaced by that version's snapshot and — with
 * `$publish` (the default) — the snapshot goes live as a NEW version that records
 * `restored_from_version`. History is never rewritten. Without `$publish` only the draft
 * changes ("restore to draft"), so the editor can adjust before publishing.
 */
final class RevertLayout
{
    public function __construct(private readonly HomeLayoutCache $cache) {}

    public function handle(CmsLayoutVersion $target, ?int $userId, bool $publish = true): ?CmsLayoutVersion
    {
        $placement = $target->placement;

        $version = DB::transaction(static function () use ($target, $placement, $userId, $publish): ?CmsLayoutVersion {
            CmsBlock::query()->where('placement', $placement->value)->lockForUpdate()->get()->each->delete();

            foreach ($target->blocks as $position => $snapshot) {
                $block = new CmsBlock([
                    'uuid' => (string) $snapshot['uuid'],
                    'placement' => $placement,
                    'type' => (string) $snapshot['type'],
                    'label' => $snapshot['label'] ?? null,
                    'content' => is_array($snapshot['content'] ?? null) ? $snapshot['content'] : [],
                    'position' => $position,
                    'is_enabled' => (bool) ($snapshot['is_enabled'] ?? true),
                    'show_en' => (bool) ($snapshot['show_en'] ?? true),
                    'show_bn' => (bool) ($snapshot['show_bn'] ?? true),
                    'show_mobile' => (bool) ($snapshot['show_mobile'] ?? true),
                    'show_desktop' => (bool) ($snapshot['show_desktop'] ?? true),
                    'starts_at' => isset($snapshot['starts_at']) ? CarbonImmutable::parse((string) $snapshot['starts_at']) : null,
                    'ends_at' => isset($snapshot['ends_at']) ? CarbonImmutable::parse((string) $snapshot['ends_at']) : null,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);
                $block->save();
            }

            if (! $publish) {
                return null;
            }

            $live = CmsLayoutVersion::query()->ofPlacement($placement)->orderByDesc('version')->lockForUpdate()->first();
            $snapshot = array_values(CmsBlock::query()->inPlacement($placement)->get()->map(static fn (CmsBlock $b): array => $b->snapshot())->all());

            $version = CmsLayoutVersion::query()->create([
                'placement' => $placement,
                'version' => ($live->version ?? 0) + 1,
                'blocks' => $snapshot,
                'block_count' => count($snapshot),
                'content_hash' => CmsLayoutVersion::hash($snapshot),
                'restored_from_version' => $target->version,
                'note' => null,
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
