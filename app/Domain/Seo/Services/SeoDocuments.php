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
 */
final readonly class SeoDocuments
{
    public function __construct(private SerpMeasure $measure) {}

    /** @return array{title: string, title_bn: string, description: string, description_bn: string, og_image: string|null} */
    public function defaults(SeoSubject $subject): array
    {
        $site = ProductSeo::SITE_NAME;
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
            'title' => $subject->name.' | '.$site,
            'title_bn' => $nameBn.' | '.$site,
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

    /** Listed in the sitemap: live, indexable and not excluded. */
    public function inSitemap(SeoSubject $subject, ?SeoMeta $meta): bool
    {
        return $subject->isLive && $this->robots($meta)->indexable() && ! ($meta !== null && $meta->sitemap_exclude) && $meta?->canonical_url === null;
    }

    private static function fallback(string $type, string $name, string $locale): string
    {
        $key = 'seo.default_description.'.$type;
        $text = __($key, ['name' => $name], $locale);

        return is_string($text) && $text !== $key ? $text : $name.' — '.ProductSeo::SITE_NAME;
    }

    private static function pick(?string $override, string $default): string
    {
        return $override !== null && trim($override) !== '' ? $override : $default;
    }
}
