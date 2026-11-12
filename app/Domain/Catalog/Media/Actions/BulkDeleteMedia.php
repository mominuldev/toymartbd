<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Actions;

use App\Domain\Catalog\Media\Exceptions\MediaInUse;
use App\Domain\Catalog\Media\Models\Media;

/**
 * Delete many files, skipping those in use (A25 rule 2: "Delete skips files that are
 * in use"). One short transaction per file (DeleteMedia), so one busy file never
 * rolls back the rest. Results come back in request order.
 */
final readonly class BulkDeleteMedia
{
    public function __construct(private DeleteMedia $delete) {}

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, status: 'deleted'|'in_use'|'not_found', used_in_count: int}>
     */
    public function handle(array $ids): array
    {
        $found = Media::query()->whereIn('id', $ids)->get()->keyBy('id');
        $outcome = [];

        $sorted = $ids;
        sort($sorted);

        foreach ($sorted as $id) {
            $media = $found->get($id);

            if (! $media instanceof Media) {
                $outcome[$id] = ['id' => $id, 'status' => 'not_found', 'used_in_count' => 0];

                continue;
            }

            try {
                $this->delete->handle($media);
                $outcome[$id] = ['id' => $id, 'status' => 'deleted', 'used_in_count' => 0];
            } catch (MediaInUse $e) {
                $outcome[$id] = ['id' => $id, 'status' => 'in_use', 'used_in_count' => count($e->usages)];
            }
        }

        return array_map(static fn (int $id): array => $outcome[$id], $ids);
    }
}
