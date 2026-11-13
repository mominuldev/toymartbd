<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Seo;

use App\Domain\Content\Blog\Models\BlogAuthor;
use App\Domain\Content\Blog\Models\BlogCategory;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Content\Blog\Models\BlogTag;
use App\Domain\Seo\Contracts\SeoSubject;
use App\Domain\Seo\Contracts\SeoSubjectProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Blog category, tag and author archive pages as SEO subjects: `/blog/category/{slug}`,
 * `/blog/tag/{slug}`, `/blog/author/{slug}`. Live = at least one live post (an empty archive
 * is a 404 on the storefront). One subclass per morph type.
 */
abstract class BlogTermSeoSubjects implements SeoSubjectProvider
{
    /** @return class-string<BlogCategory|BlogTag|BlogAuthor> */
    abstract protected function model(): string;

    abstract protected function prefix(): string;

    public function type(): string
    {
        return $this->model()::SEO_TYPE;
    }

    public function find(int $id): ?SeoSubject
    {
        $term = $this->query()->find($id);

        return $term === null ? null : $this->subject($term);
    }

    public function findBySlug(string $slug): ?SeoSubject
    {
        $term = $this->query()->where('slug', $slug)->first();

        return $term === null ? null : $this->subject($term);
    }

    public function live(): iterable
    {
        foreach ($this->query()->lazyById(500) as $term) {
            if ((int) $term->getAttribute('live_posts_count') > 0) {
                yield $this->subject($term);
            }
        }
    }

    public function livePaths(array $paths): array
    {
        $slugs = BlogPostSeoSubjects::slugsUnder($this->prefix(), $paths);
        if ($slugs === []) {
            return [];
        }

        $out = [];
        foreach ($this->query()->whereIn('slug', $slugs)->get() as $term) {
            if ((int) $term->getAttribute('live_posts_count') > 0) {
                $out[] = $this->prefix().$term->slug;
            }
        }

        return $out;
    }

    /** @return Builder<BlogCategory|BlogTag|BlogAuthor> */
    private function query(): Builder
    {
        /** @var Builder<BlogCategory|BlogTag|BlogAuthor> $query */
        $query = $this->model()::query()->withCount(['posts as live_posts_count' => static function (Builder $q): void {
            /** @var Builder<BlogPost> $q */
            $q->live();
        }]);

        return $query;
    }

    private function subject(BlogCategory|BlogTag|BlogAuthor $term): SeoSubject
    {
        $description = $term instanceof BlogCategory ? $term->description : ($term instanceof BlogAuthor ? $term->bio : null);
        $descriptionBn = $term instanceof BlogCategory ? $term->description_bn : ($term instanceof BlogAuthor ? $term->bio_bn : null);

        return new SeoSubject(
            type: $term::SEO_TYPE,
            id: $term->id,
            slug: $term->slug,
            path: $this->prefix().$term->slug,
            name: $term->name,
            nameBn: $term->name_bn,
            description: $description,
            descriptionBn: $descriptionBn,
            image: $term instanceof BlogAuthor ? $term->avatar?->url(800) : null,
            isLive: (int) $term->getAttribute('live_posts_count') > 0,
            updatedAt: $term->updated_at === null ? null : CarbonImmutable::instance($term->updated_at),
        );
    }
}
