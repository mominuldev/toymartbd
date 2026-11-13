<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Domain\Content\Actions\ArchiveCmsPage;
use App\Domain\Content\Actions\SaveCmsPage;
use App\Domain\Content\Enums\PageStatus;
use App\Domain\Content\Models\CmsPage;
use App\Domain\Seo\Actions\SaveCmsPageWithSeo;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Content\CmsPageRequest;
use App\Http\Resources\Admin\CmsPageResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Static pages (Online store → Pages, Figma B · 27 241:45525). Archive, never delete: the
 * slug history and SEO stay attached.
 */
final class PageController extends ApiController
{
    public const int PER_PAGE = 50;

    /** `GET /content/pages` — needs content.view. */
    #[QueryParameter('filter[search]', 'Contains match on title, title_bn or slug.', type: 'string')]
    #[QueryParameter('filter[status]', 'draft | published | archived.', type: "'draft'|'published'|'archived'")]
    #[QueryParameter('sort', 'Sort field; prefix `-` for descending.', type: "'title'|'-title'|'updated_at'|'-updated_at'", default: 'title')]
    #[OpenApiResponse(400, 'Unlisted filter or sort, or an invalid filter value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CmsPage::class);

        $sort = $request->query('sort');
        $descending = is_string($sort) && str_starts_with($sort, '-');

        $pages = QueryBuilder::for(CmsPage::class, $request)
            ->allowedFilters(
                AllowedFilter::callback('search', static function (Builder $query, mixed $value): void {
                    $term = is_array($value) ? implode(',', array_map(strval(...), $value)) : (string) $value;
                    $like = '%'.addcslashes($term, '\\%_').'%';
                    $query->where(static fn (Builder $q) => $q->where('title', 'like', $like)->orWhere('title_bn', 'like', $like)->orWhere('slug', 'like', $like));
                }),
                AllowedFilter::callback('status', static function (Builder $query, mixed $value): void {
                    $status = is_string($value) ? PageStatus::tryFrom($value) : null;
                    if ($status === null) {
                        throw new BadRequestHttpException('filter[status] must be one of: draft, published, archived.');
                    }
                    $query->where('status', $status->value);
                }),
            )
            ->allowedSorts('title', 'updated_at')
            ->defaultSort('title')
            ->orderBy('id', $descending ? 'desc' : 'asc')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return CmsPageResource::collection($pages);
    }

    /** `POST /content/pages` — needs content.manage. */
    public function store(CmsPageRequest $request, SaveCmsPage $save, SaveCmsPageWithSeo $withSeo): JsonResponse
    {
        $actor = $request->user()?->id;
        $page = $withSeo->handle(null, fn (): CmsPage => $save->handle(null, $request->toData(), $actor), $request->seoData(), $actor);

        return (new CmsPageResource($page, full: true))->response()->setStatusCode(201);
    }

    /** `GET /content/pages/{page}` — with content, FAQ and SEO; needs content.view. */
    public function show(CmsPage $page): CmsPageResource
    {
        $this->authorize('view', $page);

        return new CmsPageResource($page, full: true);
    }

    /** `PUT /content/pages/{page}` — replaces the document; needs content.manage. */
    public function update(CmsPageRequest $request, CmsPage $page, SaveCmsPage $save, SaveCmsPageWithSeo $withSeo): CmsPageResource
    {
        $actor = $request->user()?->id;
        $existing = clone $page;
        $saved = $withSeo->handle($existing, fn (): CmsPage => $save->handle($page, $request->toData(), $actor), $request->seoData(), $actor);

        return new CmsPageResource($saved, full: true);
    }

    /** `DELETE /content/pages/{page}` — archives (idempotent); needs content.manage. */
    public function destroy(Request $request, CmsPage $page, ArchiveCmsPage $archive): CmsPageResource
    {
        $this->authorize('delete', $page);

        return new CmsPageResource($archive->handle($page, $request->user()?->id), full: true);
    }
}
