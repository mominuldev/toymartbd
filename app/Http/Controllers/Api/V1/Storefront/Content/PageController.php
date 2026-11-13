<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront\Content;

use App\Domain\Content\Models\CmsPage;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Storefront\StorefrontPageResource;
use App\Http\Resources\Storefront\StorefrontPageSummaryResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Live static pages. Cache tags `content:pages`, `content:page:{slug}`. */
final class PageController extends ApiController
{
    public const int MAX = 200;

    /** `GET /pages` — every live page (sitemaps, footers), by title. */
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    public function index(): AnonymousResourceCollection
    {
        return StorefrontPageSummaryResource::collection(CmsPage::query()->live()->orderBy('title')->orderBy('id')->limit(self::MAX)->get());
    }

    /** `GET /pages/{slug}` — one live page; 404 when missing, draft, scheduled or archived. */
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(404, 'No live page has this slug')]
    public function show(string $slug): StorefrontPageResource
    {
        return new StorefrontPageResource(CmsPage::query()->live()->where('slug', $slug)->firstOrFail());
    }
}
