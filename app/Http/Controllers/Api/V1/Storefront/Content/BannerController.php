<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront\Content;

use App\Domain\Content\Enums\BannerContext;
use App\Domain\Content\Enums\BannerPlacement;
use App\Domain\Content\Services\LiveBanners;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Storefront\StorefrontBannerResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Live banners for one placement, highest priority first. Cache tag `content:banners`.
 * `context=cart|checkout` answers only the info strip; every other placement is empty there
 * (rule 25b).
 */
final class BannerController extends ApiController
{
    #[QueryParameter('placement', 'The slot.', type: "'announcement_bar'|'category_page'|'info_strip'|'popup'", required: true)]
    #[QueryParameter('context', 'The page asking.', type: "'general'|'cart'|'checkout'", default: 'general')]
    #[QueryParameter('category', 'Category slug, for category_page.', type: 'string')]
    #[OpenApiResponse(400, 'Missing or unknown placement or context (`bad-request`)')]
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    public function index(Request $request, LiveBanners $banners): AnonymousResourceCollection
    {
        $placement = BannerPlacement::tryFrom((string) $request->query('placement'));
        $context = BannerContext::tryFrom((string) $request->query('context', 'general'));

        if ($placement === null || $context === null) {
            throw new BadRequestHttpException('placement must be announcement_bar|category_page|info_strip|popup and context general|cart|checkout.');
        }

        $category = $request->query('category');

        return StorefrontBannerResource::collection($banners->for($placement, $context, is_string($category) && $category !== '' ? $category : null, now()));
    }
}
