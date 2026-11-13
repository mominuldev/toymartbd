<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Seo;

use App\Domain\Seo\Actions\SaveRobotsTxt;
use App\Domain\Seo\Jobs\GenerateSitemaps;
use App\Domain\Seo\Models\SeoMeta;
use App\Domain\Seo\Services\RobotsTxt;
use App\Domain\Seo\Services\SitemapBuilder;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Seo\RegenerateSitemapRequest;
use App\Http\Requests\Admin\Seo\UpdateRobotsRequest;
use App\Http\Resources\Admin\Seo\RobotsResource;
use App\Http\Resources\Admin\Seo\SitemapStatusResource;
use Illuminate\Http\JsonResponse;

/** SEO → Sitemap & robots (S-04). */
final class SitemapController extends ApiController
{
    /** `GET /seo/sitemap` — the last build; needs seo.view. */
    public function show(): SitemapStatusResource
    {
        $this->authorize('viewAny', SeoMeta::class);

        return new SitemapStatusResource(['sitemap_url' => RobotsTxt::sitemapUrl(), 'manifest' => SitemapBuilder::manifest()]);
    }

    /**
     * `POST /seo/sitemap/regenerate` — queue a rebuild (on `index`); needs seo.manage.
     *
     * @response 202 array{queued: bool}
     */
    public function regenerate(RegenerateSitemapRequest $request): JsonResponse
    {
        GenerateSitemaps::dispatch();

        return response()->json(['queued' => true], 202);
    }

    /** `GET /seo/robots` — needs seo.view. */
    public function robots(): RobotsResource
    {
        $this->authorize('viewAny', SeoMeta::class);

        return new RobotsResource;
    }

    /** `PUT /seo/robots` — needs seo.manage. */
    public function updateRobots(UpdateRobotsRequest $request, SaveRobotsTxt $save): RobotsResource
    {
        $save->handle($request->content(), $request->user()?->id);

        return new RobotsResource;
    }
}
