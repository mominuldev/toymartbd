<?php

declare(strict_types=1);

namespace App\Domain\Seo\Services;

use App\Domain\Seo\Contracts\SeoSubject;
use App\Domain\Seo\Enums\RobotsDirective;
use App\Domain\Seo\Models\SeoMeta;

/**
 * Effective search and social presentation of one page, both locales, computed on the
 * server (rule 1, P-06c): every empty override falls back to a default, Bangla falls
 * back to Bangla first (title_bn → name_bn) and only then to English.
 *
 * @phpstan-type LocaleSeo array{title: string, description: string, og_title: string, og_description: string}
 * @phpstan-type ChecklistItem array{key: string, status: 'pass'|'warn'|'fail', params: array<string, int|string>}
 */
final readonly class SeoDocuments
{
    public function __construct(private SerpMeasure $measure) {}

    /** @return array{title: string, title_bn: string, description: string, description_bn: string, og_image: string|null} */
    public function defaults(SeoSubject $subject): array
    {
        $nameBn = $subject->nameBn !== null && $subject->nameBn !== '' ? $subject->nameBn : $subject->name;

        $description = $subject->description ?? '';
        if ($description === '') {
            $description = self::fallback($subject->type, $subject->name, 'en');
        }

        $descriptionBn = $subject->descriptionBn ?? '';
        if ($descriptionBn === '') {
            $descriptionBn = self::fallback($subject->type, $nameBn, 'bn');
        }

        return [
            'title' => $subject->tagline !== null ? $subject->name.SiteTitle::separator().$subject->tagline : SiteTitle::of($subject->name),
            'title_bn' => $subject->taglineBn !== null ? $nameBn.SiteTitle::separator().$subject->taglineBn : SiteTitle::of($nameBn),
            'description' => $description,
            'description_bn' => $descriptionBn,
            'og_image' => $subject->image,
        ];
    }

    /** @return array{en: LocaleSeo, bn: LocaleSeo} */
    public function effective(SeoSubject $subject, ?SeoMeta $meta): array
    {
        $d = $this->defaults($subject);
        $title = self::pick($meta?->title, $d['title']);
        $titleBn = self::pick($meta?->title_bn, $d['title_bn']);
        $description = self::pick($meta?->description, $d['description']);
        $descriptionBn = self::pick($meta?->description_bn, $d['description_bn']);

        return [
            'en' => [
                'title' => $title,
                'description' => $description,
                'og_title' => self::pick($meta?->og_title, $title),
                'og_description' => self::pick($meta?->og_description, $description),
            ],
            'bn' => [
                'title' => $titleBn,
                'description' => $descriptionBn,
                'og_title' => self::pick($meta?->og_title_bn, $titleBn),
                'og_description' => self::pick($meta?->og_description_bn, $descriptionBn),
            ],
        ];
    }

    /** @return array{en: string, bn: string} */
    public function urls(SeoSubject $subject): array
    {
        return ['en' => SiteUrl::localized($subject->path, 'en'), 'bn' => SiteUrl::localized($subject->path, 'bn')];
    }

    /** @return array{en: array<string, mixed>, bn: array<string, mixed>} */
    public function serp(SeoSubject $subject, ?SeoMeta $meta): array
    {
        $effective = $this->effective($subject, $meta);
        $urls = $this->urls($subject);

        return [
            'en' => $this->measure->preview($effective['en']['title'], $effective['en']['description'], $urls['en']),
            'bn' => $this->measure->preview($effective['bn']['title'], $effective['bn']['description'], $urls['bn']),
        ];
    }

    public function robots(?SeoMeta $meta): RobotsDirective
    {
        return $meta === null ? RobotsDirective::IndexFollow : $meta->robots;
    }

    /**
     * The SEO score of any subject's saved document (the panel's checklist): both search
     * results fit, the focus keyword is in the title and description, there is a share image,
     * and search engines may index the page. Same item shape as ProductSeo::checklist.
     *
     * @return array{passed: int, total: int, items: list<ChecklistItem>}
     */
    public function checklist(SeoSubject $subject, ?SeoMeta $meta): array
    {
        $effective = $this->effective($subject, $meta);
        $items = [];

        foreach (['en' => '', 'bn' => '_bn'] as $locale => $suffix) {
            $title = $this->measure->title($effective[$locale]['title']);
            $description = $this->measure->description($effective[$locale]['description']);
            $items[] = ['key' => 'title_fits'.$suffix, 'status' => $title['fits'] ? 'pass' : 'fail', 'params' => ['px' => $title['px'], 'max' => $title['max']]];
            $items[] = [
                'key' => 'description_fits'.$suffix,
                'status' => $effective[$locale]['description'] === '' ? 'fail' : ($description['fits'] ? 'pass' : 'warn'),
                'params' => ['px' => $description['px'], 'max' => $description['max']],
            ];
        }

        $keyword = trim((string) $meta?->focus_keyword);
        $has = static fn (string $text): bool => $keyword !== '' && mb_stripos($text, $keyword) !== false;
        $items[] = ['key' => 'keyword_in_title', 'status' => $has($effective['en']['title']) || $has($effective['bn']['title']) ? 'pass' : 'warn', 'params' => ['keyword' => $keyword]];
        $items[] = ['key' => 'keyword_in_description', 'status' => $has($effective['en']['description']) || $has($effective['bn']['description']) ? 'pass' : 'warn', 'params' => ['keyword' => $keyword]];

        $image = $meta->og_image ?? $subject->image;
        $items[] = ['key' => 'social_image', 'status' => $image !== null && $image !== '' ? 'pass' : 'warn', 'params' => []];

        $reason = match (true) {
            ! $subject->isLive => 'not_live',
            ! $this->robots($meta)->indexable() => 'noindex',
            $meta?->canonical_url !== null => 'canonical',
            default => '',
        };
        $items[] = ['key' => 'indexable', 'status' => $reason === '' ? 'pass' : 'warn', 'params' => ['reason' => $reason]];

        return [
            'passed' => count(array_filter($items, static fn (array $i): bool => $i['status'] === 'pass')),
            'total' => count($items),
            'items' => $items,
        ];
    }

    /** Listed in the sitemap: live, indexable and not excluded. */
    public function inSitemap(SeoSubject $subject, ?SeoMeta $meta): bool
    {
        return $subject->isLive && $this->robots($meta)->indexable() && ! ($meta !== null && $meta->sitemap_exclude) && $meta?->canonical_url === null;
    }

    private static function fallback(string $type, string $name, string $locale): string
    {
        $key = 'seo.default_description.'.$type;
        $text = __($key, ['name' => $name], $locale);

        return is_string($text) && $text !== $key ? $text : $name.' — '.SiteTitle::siteName();
    }

    private static function pick(?string $override, string $default): string
    {
        return $override !== null && trim($override) !== '' ? $override : $default;
    }
}
