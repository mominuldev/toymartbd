<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront\Content;

use App\Domain\Content\Blocks\Services\HomeLayoutCache;
use App\Domain\Content\Blocks\Services\LayoutDraft;
use App\Domain\Content\Blocks\Services\LayoutResolver;
use App\Domain\Content\Blocks\ValueObjects\LayoutKey;
use App\Domain\Content\Models\CmsPage;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\HomeContentQuery;
use App\Http\Resources\Storefront\StorefrontPageLayoutResource;
use App\Support\PreviewToken;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Static pages built from sections (2026-11-16), for the Next.js server: the page document plus
 * its published sections resolved for one locale, exactly as the homepage's are. Cache tags
 * `content:pages`, `content:page:{slug}` (sent on a page save and on a layout publish) plus the
 * data tags the sections read (`products`, `categories`, `brands`, `content:testimonials`, `content:blog`).
 */
final class PageLayoutController extends ApiController
{
    /** `GET /content/pages/{slug}?locale=en|bn` — a live page and its live sections in that locale. */
    #[QueryParameter('locale', 'Storefront locale; picks the language and the per-locale visibility.', required: true, type: "'en'|'bn'")]
    #[OpenApiResponse(400, 'Missing or unknown locale, or an unlisted parameter (`bad-request`)')]
    #[OpenApiResponse(404, 'No live page has this slug')]
    public function show(Request $request, string $slug, HomeLayoutCache $cache, LayoutResolver $resolver): StorefrontPageLayoutResource
    {
        // A missing page is a 404 before the query is judged (the endpoint problem contract).
        $page = CmsPage::query()->live()->where('slug', $slug)->firstOrFail();
        $query = HomeContentQuery::fromRequest($request, preview: false);
        $live = $cache->live(LayoutKey::page($page->id));

        return new StorefrontPageLayoutResource([
            'page' => $page,
            'version' => $live['version'] ?? null,
            'published_at' => $live['published_at'] ?? null,
            'preview' => false,
            'locale' => $query->locale,
            'blocks' => $live === null ? [] : $resolver->resolve($live['blocks'], $query->locale, CarbonImmutable::now('UTC')),
        ]);
    }

    /**
     * `GET /content/pages/preview?locale=en|bn[&at=ISO]` — one page's DRAFT sections, whatever the
     * page's status, for the builder's preview. The `X-Preview-Token` (from the admin's
     * `POST /content/pages/{page}/layout/preview-token`) names the page. Never cache this response.
     */
    #[HeaderParameter('X-Preview-Token', 'Signed page preview token from POST /admin/content/pages/{page}/layout/preview-token.', required: true, type: 'string')]
    #[QueryParameter('locale', 'Storefront locale.', required: true, type: "'en'|'bn'")]
    #[QueryParameter('at', 'Preview the schedule at this instant (ISO 8601). Default: now.', type: 'string')]
    #[OpenApiResponse(403, 'Missing, forged or expired preview token (`forbidden`)')]
    public function preview(Request $request, LayoutDraft $draft, LayoutResolver $resolver): StorefrontPageLayoutResource
    {
        $query = HomeContentQuery::fromRequest($request, preview: true);
        $token = $request->header('X-Preview-Token');
        $pageId = is_string($token) ? PreviewToken::verify($token, LayoutKey::page(1)->previewTokenType()) : null;
        $page = $pageId === null ? null : CmsPage::query()->find($pageId);

        if ($page === null) {
            throw new AccessDeniedHttpException('The preview token is missing, invalid or expired.');
        }

        return new StorefrontPageLayoutResource([
            'page' => $page,
            'version' => null,
            'published_at' => null,
            'preview' => true,
            'locale' => $query->locale,
            'blocks' => $resolver->resolve($draft->snapshot(LayoutKey::page($page->id)), $query->locale, $query->at ?? CarbonImmutable::now('UTC')),
        ]);
    }
}
