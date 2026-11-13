<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Seo;

use App\Domain\Seo\Actions\SaveSeoMeta;
use App\Domain\Seo\Contracts\SeoSubject;
use App\Domain\Seo\Models\SeoMeta;
use App\Domain\Seo\Services\SeoSubjects;
use App\Domain\Seo\Services\SerpMeasure;
use App\Domain\Seo\Services\SiteUrl;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Seo\SaveSeoMetaRequest;
use App\Http\Requests\Admin\Seo\SerpPreviewRequest;
use App\Http\Resources\Admin\Seo\SeoDocumentResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The per-entity SEO panel for every type (docs/api/seo.md §2): products, categories,
 * brands, CMS pages, blog posts and guides — whatever has a registered SeoSubjectProvider.
 */
final class SeoMetaController extends ApiController
{
    public function __construct(private readonly SeoSubjects $subjects) {}

    /** `GET /seo/meta/{type}/{id}` — needs seo.view. Unknown type or id → 404. */
    public function show(string $type, int $id): SeoDocumentResource
    {
        $this->authorize('viewAny', SeoMeta::class);
        $subject = $this->subject($type, $id);

        return new SeoDocumentResource($subject, SeoMeta::for($subject->type, $subject->id)->first());
    }

    /** `PUT /seo/meta/{type}/{id}` — needs seo.manage. Replaces the stored document. */
    public function update(SaveSeoMetaRequest $request, string $type, int $id, SaveSeoMeta $save): SeoDocumentResource
    {
        $subject = $this->subject($type, $id);

        return new SeoDocumentResource($subject, $save->handle($subject, $request->toData()));
    }

    /**
     * `POST /seo/serp-preview` — the server's pixel measurement and truncation of an unsaved
     * title and description, as Google's desktop result would show them.
     *
     * @response array{title: string, title_display: string, title_px: int, title_max: int, title_truncated: bool, description: string, description_display: string, description_px: int, description_max: int, description_truncated: bool, url_display: string}
     */
    public function preview(SerpPreviewRequest $request, SerpMeasure $measure): JsonResponse
    {
        $url = $request->text('url');

        return response()->json($measure->preview(
            $request->text('title'),
            $request->text('description'),
            str_starts_with($url, 'http') ? $url : SiteUrl::base().($url === '' ? '' : '/'.ltrim($url, '/')),
        ));
    }

    private function subject(string $type, int $id): SeoSubject
    {
        return $this->subjects->for($type)?->find($id) ?? throw new NotFoundHttpException;
    }
}
