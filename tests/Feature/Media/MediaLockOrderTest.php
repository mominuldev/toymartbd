<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Media\Actions\CompleteMediaRenditions;
use App\Domain\Catalog\Media\Actions\UpdateMedia;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * P9 audit F-7 (docs/audits/LOAD-AND-CONCURRENCY.md): the product-image actions lock
 * product → product_images → media, so every media-side writer must lock the linked
 * product_images rows (ascending id) BEFORE the media row. The reverse order deadlocked.
 * SQLite ignores FOR UPDATE, so this asserts the statement order the actions issue.
 */

/** @return list<string> the tables locked, in order, while $run executes */
function mediaLockSequence(callable $run): array
{
    $locked = [];
    DB::listen(static function (QueryExecuted $query) use (&$locked): void {
        $sql = strtolower($query->sql);
        if (str_starts_with($sql, 'select') && (str_contains($sql, 'for update') || DB::getDriverName() === 'sqlite')) {
            if (preg_match('/from "?`?(product_images|media)"?`?/', $sql, $m) === 1) {
                $locked[] = $m[1];
            }
        }
    });
    $run();

    return $locked;
}

function linkedMedia(): Media
{
    $media = Media::factory()->create(['processing_status' => ImageProcessingStatus::Processing]);
    foreach ([0, 1] as $position) {
        ProductImage::factory()->create([
            'product_id' => Product::factory()->create()->id,
            'media_id' => $media->id,
            'path' => $media->base_path,
            'position' => $position,
            'is_primary' => $position === 0,
        ]);
    }

    return $media;
}

it('locks linked product images before the media row when an alt text changes', function (): void {
    $media = linkedMedia();

    $sequence = mediaLockSequence(fn () => app(UpdateMedia::class)->handle($media, ['alt_text' => 'Wooden train set']));

    expect(array_slice($sequence, 0, 2))->toBe(['product_images', 'media']);
});

it('locks linked product images before the media row when renditions complete or fail', function (): void {
    $media = linkedMedia();
    $sequence = mediaLockSequence(fn () => app(CompleteMediaRenditions::class)->handle($media->id, $media->base_path, ['widths' => [480], 'renditions' => [['w' => 480, 'f' => 'webp', 'b' => 1000]], 'bytes' => 1000, 'served' => 1000]));
    expect(array_slice($sequence, 0, 2))->toBe(['product_images', 'media']);

    $other = linkedMedia();
    $sequence = mediaLockSequence(fn () => app(CompleteMediaRenditions::class)->fail($other->id));
    expect(array_slice($sequence, 0, 2))->toBe(['product_images', 'media'])
        ->and($other->refresh()->processing_status)->toBe(ImageProcessingStatus::Failed)
        ->and(ProductImage::query()->where('media_id', $other->id)->pluck('processing_status')->unique()->all())->toBe([ImageProcessingStatus::Failed]);
});
