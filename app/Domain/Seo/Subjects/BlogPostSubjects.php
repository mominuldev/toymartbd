<?php

declare(strict_types=1);

namespace App\Domain\Seo\Subjects;

use App\Domain\Content\Blog\Enums\PostType;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Seo\Contracts\SeoSubject;
use App\Domain\Seo\Contracts\SeoSubjectProvider;
use Carbon\CarbonImmutable;

/** Blog articles (`/blog/{slug}`) and buying guides (`/guides/{slug}`) as SEO subjects. */
final class BlogPostSubjects implements SeoSubjectProvider
{
    public const string ARTICLES = '/blog/';

    public const string GUIDES = '/guides/';

    public function type(): string
    {
        return BlogPost::SEO_TYPE;
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
        $live = [];
        foreach ([self::ARTICLES => PostType::Article, self::GUIDES => PostType::BuyingGuide] as $prefix => $type) {
            $slugs = SubjectPaths::slugsUnder($prefix, $paths);
            if ($slugs === []) {
                continue;
            }
            foreach (BlogPost::query()->live()->where('type', $type->value)->whereIn('slug', $slugs)->pluck('slug') as $slug) {
                $live[] = $prefix.$slug;
            }
        }

        return $live;
    }

    private function subject(BlogPost $post): SeoSubject
    {
        $prefix = $post->type === PostType::BuyingGuide ? self::GUIDES : self::ARTICLES;

        return new SeoSubject(
            type: BlogPost::SEO_TYPE,
            id: $post->id,
            slug: $post->slug,
            path: $prefix.$post->slug,
            name: $post->title,
            nameBn: $post->title_bn,
            description: ContentText::summary($post->excerpt) ?? ContentText::summary($post->body),
            descriptionBn: ContentText::summary($post->excerpt_bn) ?? ContentText::summary($post->body_bn),
            image: $post->cover?->url(1200),
            isLive: $post->isLive(),
            updatedAt: $post->updated_at === null ? null : CarbonImmutable::instance($post->updated_at),
        );
    }
}
