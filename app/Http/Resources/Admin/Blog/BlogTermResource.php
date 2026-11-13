<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Blog;

use App\Domain\Content\Blog\Models\BlogAuthor;
use App\Domain\Content\Blog\Models\BlogCategory;
use App\Domain\Content\Blog\Models\BlogTag;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A blog category, tag or author in the admin. `description(_bn)` is the category
 * description or the author bio; `posts_count` counts every post (drafts included).
 *
 * @property BlogCategory|BlogTag|BlogAuthor $resource
 */
#[SchemaName('AdminBlogTerm')]
final class BlogTermResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $term = $this->resource;

        return [
            /** @var int */
            'id' => $term->id,
            /** @var string */
            'name' => $term->name,
            /** @var string|null */
            'name_bn' => $term->name_bn,
            /** @var string */
            'slug' => $term->slug,
            /** @var string|null */
            'description' => $term instanceof BlogCategory ? $term->description : ($term instanceof BlogAuthor ? $term->bio : null),
            /** @var string|null */
            'description_bn' => $term instanceof BlogCategory ? $term->description_bn : ($term instanceof BlogAuthor ? $term->bio_bn : null),
            /** @var int|null */
            'position' => $term instanceof BlogCategory ? $term->position : null,
            /** @var int|null */
            'user_id' => $term instanceof BlogAuthor ? $term->user_id : null,
            /** @var array{media_id: int, url: string, srcset_webp: string|null, srcset_avif: string|null, alt: string|null, alt_bn: string|null, width: int|null, height: int|null}|null */
            'avatar' => $term instanceof BlogAuthor ? BlogImage::of($term->avatar, 320) : null,
            /** @var int */
            'posts_count' => (int) ($term->getAttribute('posts_count') ?? 0),
        ];
    }
}
