<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Seo;

use App\Domain\Invoicing\Services\CompanyDetails;
use App\Domain\Invoicing\Services\StoreBranding;
use App\Domain\Seo\Contracts\SeoSubject;
use App\Domain\Seo\Contracts\SeoSubjectProvider;

/**
 * The homepage as an SEO subject (`home`, a singleton: id 1, slug `home`, path `/`), so the
 * Pages list's Home row gets the same SEO panel as every page (owner request 2026-11-18).
 *
 * It lives beside Site & branding because branding IS the homepage's default `<head>`: the
 * default title is "{site name}{separator}{tagline}" (absolute, no second suffix) and the default
 * description is the branding search description — exactly what the storefront showed before
 * the SEO document existed (toymartbd-storefront lib/seo/metadata.ts `homeMetadata`), so an
 * untouched document changes nothing. The default share image is the branding one. Always live;
 * it feeds no sitemap rows (the sitemap builder lists `/` itself).
 */
final readonly class HomeSeoSubjects implements SeoSubjectProvider
{
    public const string TYPE = 'home';

    public const int ID = 1;

    public const string SLUG = 'home';

    /** The share image rendition, as the storefront's store info serves it. */
    private const int SHARE_WIDTH = 1200;

    public function __construct(private StoreBranding $branding, private CompanyDetails $company) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function find(int $id): ?SeoSubject
    {
        return $id === self::ID ? $this->subject() : null;
    }

    public function findBySlug(string $slug): ?SeoSubject
    {
        return $slug === self::SLUG ? $this->subject() : null;
    }

    public function live(): iterable
    {
        return [];
    }

    public function livePaths(array $paths): array
    {
        return in_array('/', $paths, true) ? ['/'] : [];
    }

    private function subject(): SeoSubject
    {
        $brand = $this->branding->saved();
        $company = $this->company->saved();
        $text = static fn (array $values, string $field): ?string => is_string($values[$field] ?? null) && trim((string) $values[$field]) !== '' ? trim((string) $values[$field]) : null;

        // The storefront's chain (lib/store-info.ts): branding site name → business name → catalog name.
        $name = $text($brand, 'site_name_en') ?? $text($company, 'name_en') ?? CompanyDetails::DEFAULT_NAMES['name_en'];
        $nameBn = $text($brand, 'site_name_bn') ?? $text($company, 'name_bn') ?? CompanyDetails::DEFAULT_NAMES['name_bn'];
        $tagline = $text($brand, 'tagline_en') ?? (string) __('seo.home_tagline', [], 'en');
        $taglineBn = $text($brand, 'tagline_bn') ?? (string) __('seo.home_tagline', [], 'bn');

        return new SeoSubject(
            type: self::TYPE,
            id: self::ID,
            slug: self::SLUG,
            path: '/',
            name: $name,
            nameBn: $nameBn,
            description: $text($brand, 'description_en') ?? (string) __('seo.default_description.home', [], 'en'),
            descriptionBn: $text($brand, 'description_bn') ?? (string) __('seo.default_description.home', [], 'bn'),
            image: StoreBranding::rendition(StoreBranding::readyImage(is_int($brand['share_image_media_id'] ?? null) ? $brand['share_image_media_id'] : null), self::SHARE_WIDTH)['url'] ?? null,
            isLive: true,
            updatedAt: null,
            tagline: $tagline,
            taglineBn: $taglineBn,
        );
    }
}
