<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Blog;

use App\Domain\Content\Blog\Models\BlogPost;
use App\Http\Resources\ApiResource;
use App\Support\DhakaDate;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A row of the admin posts list. `published_at` is UTC ISO-8601; `published_at_dhaka` the
 * same instant as Dhaka wall time ("Y-m-d H:i") for display.
 *
 * @property BlogPost $resource
 */
#[SchemaName('AdminBlogPostRow')]
final class BlogPostListResource extends ApiResource
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
            'status' => $post->status->value,
            /** @var bool */
            'is_live' => $post->isLive(),
            'is_featured' => $post->is_featured,
            /** @var string|null */
            'published_at' => $post->published_at?->toIso8601String(),
            /** @var string|null */
            'published_at_dhaka' => $post->published_at === null ? null : DhakaDate::local($post->published_at)->format('Y-m-d H:i'),
            'reading_minutes' => $post->reading_minutes,
            /** @var array{id: int, name: string, name_bn: string|null}|null */
            'category' => $post->category === null ? null : ['id' => $post->category->id, 'name' => $post->category->name, 'name_bn' => $post->category->name_bn],
            /** @var array{id: int, name: string, name_bn: string|null}|null */
            'author' => $post->author === null ? null : ['id' => $post->author->id, 'name' => $post->author->name, 'name_bn' => $post->author->name_bn],
            /** @var array{media_id: int, url: string, srcset_webp: string|null, srcset_avif: string|null, alt: string|null, alt_bn: string|null, width: int|null, height: int|null}|null */
            'cover' => BlogImage::of($post->cover, 320),
            /** @var string|null */
            'updated_at' => $post->updated_at?->toIso8601String(),
        ];
    }
}
