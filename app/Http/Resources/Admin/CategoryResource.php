<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Models\Category;
use App\Http\Resources\ApiResource;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * A category in the admin. `depth` is 0 for a root. `position` is the index among
 * its siblings. `children` is present only in the `?tree=1` listing, where it holds
 * the same shape recursively. `is_active` is false once archived.
 *
 * @property Category $resource
 */
#[SchemaName('Category')]
final class CategoryResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $category = $this->resource;
        $depth = $category->getAttribute('depth');

        return [
            'id' => $category->id,
            /** @var int|null */
            'parent_id' => $category->parent_id,
            'name' => $category->name,
            /** @var string|null */
            'name_bn' => $category->name_bn,
            'slug' => $category->slug,
            /** @var string|null */
            'description' => $category->description,
            /** @var string|null */
            'description_bn' => $category->description_bn,
            /** @var string|null */
            'icon' => $category->icon,
            /** @var string|null */
            'image_url' => self::publicUrl($category->image_path),
            'is_featured' => $category->is_featured,
            'show_in_menu' => $category->show_in_menu,
            'is_active' => $category->isActive(),
            'position' => $category->position,
            /** @var int */
            'depth' => is_numeric($depth) ? (int) $depth : Category::query()->treeAncestorsOf($category)->count(),
            /**
             * ISO 8601, UTC.
             *
             * @var string
             *
             * @format date-time
             */
            'created_at' => self::utcIso($category->created_at),
            /**
             * ISO 8601, UTC.
             *
             * @var string
             *
             * @format date-time
             */
            'updated_at' => self::utcIso($category->updated_at),
            /** @var list<Category> */
            'children' => self::collection($this->whenLoaded('children')),
        ];
    }

    public static function publicUrl(?string $path): ?string
    {
        return $path !== null ? Storage::disk('public')->url($path) : null;
    }

    private static function utcIso(?DateTimeInterface $at): string
    {
        return $at === null ? '' : DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
}
