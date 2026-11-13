<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Seo;

use App\Domain\Content\Blog\Enums\PostType;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Content\Blog\Services\BlogHtml;
use App\Domain\Seo\Contracts\SeoSubject;
use App\Domain\Seo\Contracts\SeoSubjectProvider;
use Carbon\CarbonImmutable;

/**
 * Blog posts and buying guides as SEO subjects (docs/api/seo.md §1), one morph type
 * `blog_post` for both so a post that becomes a guide keeps its seo_meta and slug history.
 * Path: `/blog/{slug}` for an article, `/guides/{slug}` for a guide. Live = the `live` scope.
 */
final class BlogPostSeoSubjects implements SeoSubjectProvider
{
    public function type(): string
    {
        return BlogPost::SEO_TYPE;
    }

    public static function path(BlogPost $post): string
    {
        return ($post->type === PostType::BuyingGuide ? '/guides/' : '/blog/').$post->slug;
    }

    public function find(int $id): ?SeoSubject
    {
        $post = BlogPost::query()->with('cover')->find($id);

        return $post === null ? null : $this->subject($post);
    }

    public function findBySlug(string $slug): ?SeoSubject
    {
        $post = BlogPost::query()->with('cover')->where('slug', $slug)->first();

        return $post === null ? null : $this->subject($post);
    }

    public function live(): iterable
    {
        foreach (BlogPost::query()->live()->with('cover')->lazyById(500) as $post) {
            yield $this->subject($post);
        }
    }

    public function livePaths(array $paths): array
    {
        $out = [];
        foreach ([PostType::Article->value => '/blog/', PostType::BuyingGuide->value => '/guides/'] as $type => $prefix) {
            $slugs = self::slugsUnder($prefix, $paths);
            if ($slugs === []) {
                continue;
            }
            foreach (BlogPost::query()->live()->where('type', $type)->whereIn('slug', $slugs)->pluck('slug') as $slug) {
                $out[] = $prefix.$slug;
            }
        }

        return $out;
    }

    private function subject(BlogPost $post): SeoSubject
    {
        return new SeoSubject(
            type: BlogPost::SEO_TYPE,
            id: $post->id,
            slug: $post->slug,
            path: self::path($post),
            name: $post->title,
            nameBn: $post->title_bn,
            description: $post->excerpt ?? self::summary($post->body),
            descriptionBn: $post->excerpt_bn ?? self::summary($post->body_bn),
            image: $post->cover?->url(1200),
            isLive: $post->isLive(),
            updatedAt: $post->updated_at === null ? null : CarbonImmutable::instance($post->updated_at),
        );
    }

    private static function summary(?string $html): ?string
    {
        $text = BlogHtml::plainText($html);

        return $text === '' ? null : mb_substr($text, 0, 160);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function slugsUnder(string $prefix, array $paths): array
    {
        $slugs = [];
        foreach ($paths as $path) {
            if (str_starts_with($path, $prefix)) {
                $slug = substr($path, strlen($prefix));
                if ($slug !== '' && ! str_contains($slug, '/')) {
                    $slugs[] = $slug;
                }
            }
        }

        return array_values(array_unique($slugs));
    }
}
