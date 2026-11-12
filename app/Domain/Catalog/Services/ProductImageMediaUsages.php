<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Media\Contracts\MediaUsageProvider;
use App\Domain\Catalog\Media\DTOs\MediaUsage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Product gallery images as media usages (`product_images.media_id`). Archived
 * (soft-deleted) products count too: their rows still reference the file, and the
 * foreign key would refuse the delete anyway.
 */
final class ProductImageMediaUsages implements MediaUsageProvider
{
    public function referencing(): Builder
    {
        return DB::table('product_images')->select(DB::raw(1))->whereColumn('product_images.media_id', 'media.id');
    }

    public function subjectMatching(string $like): Builder
    {
        return DB::table('product_images')
            ->select(DB::raw(1))
            ->join('products', 'products.id', '=', 'product_images.product_id')
            ->whereColumn('product_images.media_id', 'media.id')
            ->where(static function (Builder $q) use ($like): void {
                $q->where('products.name', 'like', $like)->orWhere('products.name_bn', 'like', $like);
            });
    }

    public function usagesFor(array $mediaIds): array
    {
        $rows = DB::table('product_images')
            ->join('products', 'products.id', '=', 'product_images.product_id')
            ->whereIn('product_images.media_id', $mediaIds)
            ->orderBy('products.name')
            ->orderBy('product_images.product_id')
            ->orderBy('product_images.position')
            ->orderBy('product_images.id')
            ->get([
                'product_images.media_id', 'product_images.product_id', 'product_images.position',
                'product_images.is_primary', 'products.name', 'products.name_bn',
            ]);

        $out = [];

        foreach ($rows as $row) {
            $productId = (int) $row->product_id;
            $out[(int) $row->media_id][] = new MediaUsage(
                type: 'product_image',
                subjectType: 'product',
                subjectId: $productId,
                title: (string) $row->name,
                titleBn: is_string($row->name_bn) && $row->name_bn !== '' ? $row->name_bn : null,
                adminPath: "/admin/products/{$productId}",
                position: ((int) $row->position) + 1,
                isPrimary: (bool) $row->is_primary,
            );
        }

        return $out;
    }
}
