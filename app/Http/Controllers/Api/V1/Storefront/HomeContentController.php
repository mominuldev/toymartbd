<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Content\Blocks\Enums\BlockPlacement;
use App\Domain\Content\Blocks\Services\HomeLayoutCache;
use App\Domain\Content\Blocks\Services\LayoutDraft;
use App\Domain\Content\Blocks\Services\LayoutResolver;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\HomeContentQuery;
use App\Http\Resources\Storefront\StorefrontHomeResource;
use App\Support\PreviewToken;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The CMS homepage for the Next.js server (Phase 7). The published version is cached in
 * Laravel until the next publish; the storefront caches the response under `content:home`
 * (plus `products`, `categories`, `brands`, `content:testimonials`, `content:blog`), purged on
 * publish and at every schedule boundary (`toymart:content:revalidate-scheduled`).
 */
final class HomeContentController extends ApiController
{
    /** `GET /content/home?locale=en|bn` — the live homepage blocks visible now in that locale. */
    #[QueryParameter('locale', 'Storefront locale; picks the language and the per-locale visibility.', required: true, type: "'en'|'bn'")]
    #[OpenApiResponse(400, 'Missing or unknown locale, or an unlisted parameter (`bad-request`)')]
    public function home(Request $request, HomeLayoutCache $cache, LayoutResolver $resolver): StorefrontHomeResource
    {
        $query = HomeContentQuery::fromRequest($request, preview: false);
        $live = $cache->live(BlockPlacement::Home);

        return new StorefrontHomeResource([
            'version' => $live['version'] ?? null,
            'published_at' => $live['published_at'] ?? null,
            'preview' => false,
            'locale' => $query->locale,
            'blocks' => $live === null ? [] : $resolver->resolve($live['blocks'], $query->locale, CarbonImmutable::now('UTC')),
        ]);
    }

    /**
     * `GET /content/home/preview?locale=en|bn[&at=ISO]` — the DRAFT, for the builder's live
     * preview. Needs a valid, unexpired preview token from the admin in `X-Preview-Token`;
     * `at` previews the schedule at another instant. Never cache this response.
     */
    #[HeaderParameter('X-Preview-Token', 'Signed homepage preview token from POST /admin/content/home/preview-token.', required: true, type: 'string')]
    #[QueryParameter('locale', 'Storefront locale.', required: true, type: "'en'|'bn'")]
    #[QueryParameter('at', 'Preview the schedule at this instant (ISO 8601). Default: now.', type: 'string')]
    #[OpenApiResponse(403, 'Missing, forged or expired preview token (`forbidden`)')]
    public function preview(Request $request, LayoutDraft $draft, LayoutResolver $resolver): StorefrontHomeResource
    {
        $query = HomeContentQuery::fromRequest($request, preview: true);
        $token = $request->header('X-Preview-Token');

        if (! is_string($token) || PreviewToken::verify($token, BlockPlacement::Home->previewTokenType()) === null) {
            throw new AccessDeniedHttpException('The preview token is missing, invalid or expired.');
        }

        return new StorefrontHomeResource([
            'version' => null,
            'published_at' => null,
            'preview' => true,
            'locale' => $query->locale,
            'blocks' => $resolver->resolve($draft->snapshot(BlockPlacement::Home), $query->locale, $query->at ?? CarbonImmutable::now('UTC')),
        ]);
    }
}
