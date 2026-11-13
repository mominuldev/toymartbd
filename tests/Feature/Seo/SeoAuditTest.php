<?php

declare(strict_types=1);

use App\Domain\Content\Models\CmsPage;
use App\Domain\Seo\Services\LivePaths;
use App\Domain\Seo\Services\RedirectMap;
use App\Domain\Seo\Services\RobotsTxt;
use App\Domain\Seo\Services\SitemapBuilder;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * Phase 9 SEO audit (docs/audits/SEO-AND-COVERAGE.md): fixes found by crawling the storefront
 * against a DemoDataSeeder API.
 *
 *  - The sitemap must list every indexable, self-canonical URL. The storefront's own routes
 *    (/delivery-charge, /flash-sale, /track and the built-in About page) render `index,follow`
 *    with a self canonical in both locales but were missing from the `static` partition.
 *  - /flash-sale is a live storefront route, so a manual redirect must not be able to shadow it.
 *  - A sitemap must not submit a URL the served robots.txt blocks (the demo robots.txt blocks
 *    /track, which the sitemap now lists).
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => 'https://toymartbd.com', 'services.storefront.revalidate_secret' => '', 'media.cdn_url' => null]);
    Storage::fake('local');
    RedirectMap::forget();
});

/** @return list<string> */
function seoAuditLocs(string $file): array
{
    $xml = simplexml_load_string((string) SitemapBuilder::read($file));
    expect($xml)->not->toBeFalse();

    $locs = [];
    foreach ($xml->url as $url) {
        $locs[] = (string) $url->loc;
    }

    return $locs;
}

it('lists the storefront\'s own indexable routes in the static sitemap, in both locales with hreflang', function (): void {
    app(SitemapBuilder::class)->build();

    $en = seoAuditLocs('sitemap-static-en-1.xml');
    $bn = seoAuditLocs('sitemap-static-bn-1.xml');
    foreach (['/delivery-charge', '/flash-sale', '/track', '/pages/about'] as $path) {
        expect($en)->toContain('https://toymartbd.com'.$path)
            ->and($bn)->toContain('https://toymartbd.com/bn'.$path);
    }
    expect($en)->toContain('https://toymartbd.com', 'https://toymartbd.com/toys', 'https://toymartbd.com/categories', 'https://toymartbd.com/brands')
        ->and(count($en))->toBe(count(array_unique($en)))
        // Never a private or noindex route.
        ->and(implode(' ', $en))->not->toContain('/cart')->not->toContain('/checkout')->not->toContain('/search')->not->toContain('/account');

    $raw = (string) SitemapBuilder::read('sitemap-static-bn-1.xml');
    expect($raw)->toContain('<loc>https://toymartbd.com/bn/track</loc><xhtml:link rel="alternate" hreflang="en" href="https://toymartbd.com/track"/><xhtml:link rel="alternate" hreflang="bn" href="https://toymartbd.com/bn/track"/><xhtml:link rel="alternate" hreflang="x-default" href="https://toymartbd.com/track"/>');
});

it('lists /pages/about once — in the pages partition — when a live CMS About page exists', function (): void {
    CmsPage::query()->create(['slug' => 'about', 'title' => 'About us', 'title_bn' => 'আমাদের কথা', 'content' => '<p>Toys for Bangladesh.</p>', 'status' => 'published', 'published_at' => now()->subDay()]);

    app(SitemapBuilder::class)->build();

    expect(seoAuditLocs('sitemap-pages-en-1.xml'))->toContain('https://toymartbd.com/pages/about')
        ->and(seoAuditLocs('sitemap-static-en-1.xml'))->not->toContain('https://toymartbd.com/pages/about')
        ->and(seoAuditLocs('sitemap-static-en-1.xml'))->toContain('https://toymartbd.com/delivery-charge');
});

it('treats /flash-sale as a reserved storefront route that no redirect may shadow', function (): void {
    expect(app(LivePaths::class)->reserved('/flash-sale'))->toBeTrue();
});

it('matches robots.txt the way Google does: longest rule wins, Allow wins a tie, * and $ wildcards', function (): void {
    $robots = "User-agent: Googlebot\nDisallow: /toys\n\nUser-agent: *\nAllow: /\nDisallow: /search\nDisallow: /bn/search\nDisallow: /*?sort=\nDisallow: /private$\nAllow: /search/help\nDisallow: /track\n";

    expect(RobotsTxt::allows('/toys', $robots))->toBeTrue() // only Googlebot's group blocks it
        ->and(RobotsTxt::allows('/search', $robots))->toBeFalse()
        ->and(RobotsTxt::allows('/search?q=lego', $robots))->toBeFalse()
        ->and(RobotsTxt::allows('/bn/search', $robots))->toBeFalse()
        ->and(RobotsTxt::allows('/search/help', $robots))->toBeTrue() // the longer Allow wins
        ->and(RobotsTxt::allows('/categories/x?sort=newest', $robots))->toBeFalse()
        ->and(RobotsTxt::allows('/private', $robots))->toBeFalse()
        ->and(RobotsTxt::allows('/private/page', $robots))->toBeTrue() // `$` anchors
        ->and(RobotsTxt::allows('/products/lego', $robots))->toBeTrue()
        ->and(RobotsTxt::allows('/anything', "User-agent: *\nDisallow:\n"))->toBeTrue(); // empty Disallow allows all
});

it('never submits a URL in the sitemap that the served robots.txt blocks', function (): void {
    Setting::put(RobotsTxt::SETTING, "User-agent: *\nAllow: /\nDisallow: /track\nDisallow: /bn/flash-sale\n", SettingGroup::Seo);

    app(SitemapBuilder::class)->build();

    $en = seoAuditLocs('sitemap-static-en-1.xml');
    $bn = seoAuditLocs('sitemap-static-bn-1.xml');
    expect($en)->not->toContain('https://toymartbd.com/track')
        ->and($bn)->toContain('https://toymartbd.com/bn/track') // robots paths are per locale: /track does not block /bn/track
        ->and($en)->toContain('https://toymartbd.com/flash-sale') // only the Bangla URL is blocked
        ->and($bn)->not->toContain('https://toymartbd.com/bn/flash-sale')
        ->and($en)->toContain('https://toymartbd.com/delivery-charge');

    // The shipped default blocks only private routes, none of which is ever listed.
    Setting::query()->where('key', RobotsTxt::SETTING)->delete();
    app(SitemapBuilder::class)->build();
    expect(seoAuditLocs('sitemap-static-en-1.xml'))->toContain('https://toymartbd.com/track');
});
