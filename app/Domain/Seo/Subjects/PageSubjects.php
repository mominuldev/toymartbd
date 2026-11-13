<?php

declare(strict_types=1);

namespace App\Domain\Seo\Subjects;

use App\Domain\Content\Models\CmsPage;
use App\Domain\Seo\Contracts\SeoSubject;
use App\Domain\Seo\Contracts\SeoSubjectProvider;
use Carbon\CarbonImmutable;

/** CMS static pages (`/pages/{slug}`) as SEO subjects. Live = published and reached. */
final class PageSubjects implements SeoSubjectProvider
{
    public const string PREFIX = '/pages/';

    public function type(): string
    {
        return CmsPage::MORPH;
    }

    public function find(int $id): ?SeoSubject
    {
        $page = CmsPage::query()->find($id);

        return $page === null ? null : $this->subject($page);
    }

    public function findBySlug(string $slug): ?SeoSubject
    {
        $page = CmsPage::query()->where('slug', $slug)->first();

        return $page === null ? null : $this->subject($page);
    }

    public function live(): iterable
    {
        foreach (CmsPage::query()->live()->lazyById(500) as $page) {
            yield $this->subject($page);
        }
    }

    public function livePaths(array $paths): array
    {
        $slugs = SubjectPaths::slugsUnder(self::PREFIX, $paths);
        if ($slugs === []) {
            return [];
        }

        /** @var list<string> */
        return CmsPage::query()->live()->whereIn('slug', $slugs)->pluck('slug')
            ->map(static fn (string $slug): string => self::PREFIX.$slug)->values()->all();
    }

    private function subject(CmsPage $page): SeoSubject
    {
        return new SeoSubject(
            type: CmsPage::MORPH,
            id: $page->id,
            slug: $page->slug,
            path: self::PREFIX.$page->slug,
            name: $page->title,
            nameBn: $page->title_bn,
            description: ContentText::summary($page->content),
            descriptionBn: ContentText::summary($page->content_bn),
            image: null,
            isLive: $page->isLive(),
            updatedAt: $page->updated_at === null ? null : CarbonImmutable::instance($page->updated_at),
        );
    }
}
