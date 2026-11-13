<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront\Seo;

use App\Domain\Seo\Actions\RecordRedirectHit;
use App\Domain\Seo\Models\SeoMeta;
use App\Domain\Seo\Services\RedirectMap;
use App\Domain\Seo\Services\RobotsTxt;
use App\Domain\Seo\Services\SeoSubjects;
use App\Domain\Seo\Services\SitemapBuilder;
use App\Domain\Seo\Services\SiteTitle;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\Seo\RecordRedirectHitRequest;
use App\Http\Resources\Storefront\Seo\StorefrontSeoResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The storefront's SEO reads (docs/api/seo.md §3): head data, redirects, sitemaps, robots.txt. */
final class SeoController extends ApiController
{
    /**
     * `GET /seo/settings` — how a `<title>` ends (config/seo.php): the storefront's layout title
     * template is `%s{title_separator}{site_name}`, the same as every server default title.
     *
     * @response array{site_name: string, title_separator: string}
     */
    public function settings(): JsonResponse
    {
        return response()->json(['site_name' => SiteTitle::siteName(), 'title_separator' => SiteTitle::separator()]);
    }

    /** `GET /seo/{type}/{slug}` — effective SEO of a live page, both locales; 404 when not live. */
    public function show(string $type, string $slug, SeoSubjects $subjects): StorefrontSeoResource
    {
        $subject = $subjects->for($type)?->findBySlug($slug);
        if ($subject === null || ! $subject->isLive) {
            throw new NotFoundHttpException;
        }

        return new StorefrontSeoResource($subject, SeoMeta::for($subject->type, $subject->id)->first());
    }

    /**
     * `GET /redirects` — the whole collapsed redirect map (manual + slug history), live
     * URLs pruned, no chains, no loops. Paths are locale-independent.
     *
     * @response array{redirects: list<array{id: int|null, from: string, to: string, status: 301|302}>}
     */
    public function redirects(RedirectMap $map): JsonResponse
    {
        return response()->json(['redirects' => $map->entries()]);
    }

    /** `POST /redirects/{id}/hit` — count one storefront hit on a manual redirect. */
    public function hit(RecordRedirectHitRequest $request, int $id, RecordRedirectHit $record): Response
    {
        $record->handle($id);

        return response()->noContent();
    }

    /** `GET /sitemaps/{file}` — one sitemap file (XML) from the last build; 404 when absent. */
    public function sitemap(string $file): Response
    {
        $xml = SitemapBuilder::read($file) ?? throw new NotFoundHttpException;

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /** `GET /robots` — robots.txt as served (text/plain), the sitemap line guaranteed. */
    public function robots(): Response
    {
        return response(RobotsTxt::served(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
