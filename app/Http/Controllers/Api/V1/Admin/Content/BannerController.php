<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Domain\Content\Actions\DeleteBanner;
use App\Domain\Content\Actions\SaveBanner;
use App\Domain\Content\Enums\BannerPlacement;
use App\Domain\Content\Models\Banner;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Content\BannerRequest;
use App\Http\Resources\Admin\BannerResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Banners (Online store → Banners): announcement bar, category page, info strip, popup. */
final class BannerController extends ApiController
{
    public const int MAX = 300;

    /** `GET /content/banners` — newest first per placement; needs content.view. */
    #[QueryParameter('filter[placement]', 'One placement only.', type: "'announcement_bar'|'category_page'|'info_strip'|'popup'")]
    #[OpenApiResponse(400, 'Unknown filter or placement (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Banner::class);

        $filters = $request->query('filter', []);
        if (! is_array($filters) || array_diff(array_keys($filters), ['placement']) !== []) {
            throw new BadRequestHttpException('Only filter[placement] is allowed.');
        }

        $query = Banner::query()->with(['media', 'category']);
        if (isset($filters['placement'])) {
            $placement = is_string($filters['placement']) ? BannerPlacement::tryFrom($filters['placement']) : null;
            if ($placement === null) {
                throw new BadRequestHttpException('filter[placement] must be one of: announcement_bar, category_page, info_strip, popup.');
            }
            $query->where('placement', $placement->value);
        }

        return BannerResource::collection($query->orderBy('placement')->orderByDesc('priority')->orderByDesc('id')->limit(self::MAX)->get());
    }

    /** `POST /content/banners` — needs content.manage. */
    public function store(BannerRequest $request, SaveBanner $save): JsonResponse
    {
        $banner = $save->handle(null, $request->toData(), $request->user()?->id);

        return (new BannerResource($banner->load(['media', 'category'])))->response()->setStatusCode(201);
    }

    /** `GET /content/banners/{banner}` — needs content.view. */
    public function show(Banner $banner): BannerResource
    {
        $this->authorize('view', $banner);

        return new BannerResource($banner->load(['media', 'category']));
    }

    /** `PUT /content/banners/{banner}` — needs content.manage. */
    public function update(BannerRequest $request, Banner $banner, SaveBanner $save): BannerResource
    {
        return new BannerResource($save->handle($banner, $request->toData(), $request->user()?->id)->load(['media', 'category']));
    }

    /** `DELETE /content/banners/{banner}` — needs content.manage. */
    public function destroy(Banner $banner, DeleteBanner $delete): Response
    {
        $this->authorize('delete', $banner);
        $delete->handle($banner);

        return response()->noContent();
    }
}
