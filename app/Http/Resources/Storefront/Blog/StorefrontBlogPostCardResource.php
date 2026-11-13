<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront\Blog;

use App\Domain\Content\Blog\Models\BlogPost;
use App\Http\Resources\Admin\Blog\BlogImage;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A blog card (lists, taxonomy pages, related posts, the home-page teaser). Needs
 * `category`, `author` and `cover` loaded. Reading time is computed server-side.
 *
 * @property BlogPost $resource
 */
#[SchemaName('StorefrontBlogPostCard')]
final class StorefrontBlogPostCardResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $post = $this->resource;

        return [
            'id' => $post->id,
            'type' => $post->type->value,
            'slug' => $post->slug,
            'title' => $post->title,
            /** @var string|null */
            'title_bn' => $post->title_bn,
            /** @var string|null */
            'excerpt' => $post->excerpt,
            /** @var string|null */
            'excerpt_bn' => $post->excerpt_bn,
            /** @var array{media_id: int, url: string, srcset_webp: string|null, srcset_avif: string|null, alt: string|null, alt_bn: string|null, width: int|null, height: int|null}|null */
            'cover' => BlogImage::of($post->cover),
            /** @var array{name: string, name_bn: string|null, slug: string}|null */
            'category' => $post->category === null ? null : ['name' => $post->category->name, 'name_bn' => $post->category->name_bn, 'slug' => $post->category->slug],
            /** @var array{name: string, name_bn: string|null, slug: string}|null */
            'author' => $post->author === null ? null : ['name' => $post->author->name, 'name_bn' => $post->author->name_bn, 'slug' => $post->author->slug],
            'reading_minutes' => $post->reading_minutes,
            /** @var int|null */
            'reading_minutes_bn' => $post->reading_minutes_bn,
            /** @var string|null */
            'published_at' => $post->published_at?->toIso8601String(),
            'is_featured' => $post->is_featured,
        ];
    }
}
