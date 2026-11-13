<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Seo;

use App\Domain\Seo\Actions\DeleteRedirect;
use App\Domain\Seo\Actions\ImportRedirects;
use App\Domain\Seo\Actions\SaveRedirect;
use App\Domain\Seo\Models\Redirect;
use App\Domain\Seo\Models\SlugHistory;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Seo\ImportRedirectsRequest;
use App\Http\Requests\Admin\Seo\SaveRedirectRequest;
use App\Http\Resources\Admin\Seo\RedirectResource;
use App\Http\Resources\Admin\Seo\SlugHistoryResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** SEO → Redirects (S-04): manual redirects, CSV import and the automatic slug-change redirects. */
final class RedirectController extends ApiController
{
    public const int PER_PAGE = 25;

    /** `GET /seo/redirects` — needs seo.view. */
    #[QueryParameter('filter[q]', 'Contains, on from_path, to_path or note.', type: 'string')]
    #[QueryParameter('filter[status_code]', 'Only 301 or only 302.', type: "'301'|'302'")]
    #[QueryParameter('filter[is_active]', 'Only active (1) or inactive (0).', type: "'0'|'1'")]
    #[QueryParameter('filter[source]', 'manual or import.', type: "'manual'|'import'")]
    #[QueryParameter('sort', 'Sort field; prefix `-` for descending.', type: "'from_path'|'-from_path'|'hit_count'|'-hit_count'|'last_hit_at'|'-last_hit_at'|'created_at'|'-created_at'", default: '-created_at')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Redirect::class);

        $sort = $request->query('sort');
        $descending = ! is_string($sort) || str_starts_with($sort, '-');

        $rows = QueryBuilder::for(Redirect::class, $request)
            ->allowedFilters(
                AllowedFilter::callback('q', static function (Builder $query, mixed $value): void {
                    $like = '%'.addcslashes(is_array($value) ? implode(',', array_map(strval(...), $value)) : (string) $value, '\\%_').'%';
                    $query->where(static fn (Builder $q) => $q->where('from_path', 'like', $like)->orWhere('to_path', 'like', $like)->orWhere('note', 'like', $like));
                }),
                AllowedFilter::callback('status_code', static function (Builder $query, mixed $value): void {
                    if (! in_array((string) (is_scalar($value) ? $value : ''), ['301', '302'], true)) {
                        throw new BadRequestHttpException('filter[status_code] must be 301 or 302.');
                    }
                    $query->where('status_code', (int) $value);
                }),
                AllowedFilter::callback('is_active', static function (Builder $query, mixed $value): void {
                    if (! in_array((string) (is_scalar($value) ? $value : ''), ['0', '1', 'true', 'false'], true)) {
                        throw new BadRequestHttpException('filter[is_active] must be 0 or 1.');
                    }
                    $query->where('is_active', in_array((string) $value, ['1', 'true'], true));
                }),
                AllowedFilter::callback('source', static function (Builder $query, mixed $value): void {
                    if (! in_array($value, [Redirect::SOURCE_MANUAL, Redirect::SOURCE_IMPORT], true)) {
                        throw new BadRequestHttpException('filter[source] must be manual or import.');
                    }
                    $query->where('source', $value);
                }),
            )
            ->allowedSorts('from_path', 'hit_count', 'last_hit_at', 'created_at')
            ->defaultSort('-created_at')
            ->orderBy('id', $descending ? 'desc' : 'asc')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return RedirectResource::collection($rows);
    }

    /** `POST /seo/redirects` — needs seo.manage. Conflict, loop and chain checks → 422. */
    public function store(SaveRedirectRequest $request, SaveRedirect $save): JsonResponse
    {
        $redirect = $save->handle(null, $request->toData(), $request->user()?->id);

        return (new RedirectResource($redirect))->response()->setStatusCode(201);
    }

    /** `PATCH /seo/redirects/{redirect}` — needs seo.manage. */
    public function update(SaveRedirectRequest $request, Redirect $redirect, SaveRedirect $save): RedirectResource
    {
        return new RedirectResource($save->handle($redirect, $request->toData($redirect), $request->user()?->id));
    }

    /** `DELETE /seo/redirects/{redirect}` — needs seo.manage. */
    public function destroy(Redirect $redirect, DeleteRedirect $delete): Response
    {
        $this->authorize('delete', $redirect);
        $delete->handle($redirect);

        return response()->noContent();
    }

    /**
     * `POST /seo/redirects/import` — CSV, all or nothing. 200 with counts, or 422 with
     * the row errors (nothing saved).
     *
     * @response array{created: int, updated: int, errors: list<array{line: int, field: string, message: string}>}
     */
    public function import(ImportRedirectsRequest $request, ImportRedirects $import): JsonResponse
    {
        $file = $request->file('file');
        $path = $file instanceof UploadedFile ? (string) $file->getRealPath() : '';
        $result = $import->handle($path, $request->user()?->id);

        return response()->json($result, $result['errors'] === [] ? 200 : 422);
    }

    /** `GET /seo/slug-history` — automatic redirects from slug changes; needs seo.view. */
    #[QueryParameter('filter[type]', 'One entity type (product, category, brand, page, blog_post, …).', type: 'string')]
    public function slugHistory(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SlugHistory::class);

        $rows = QueryBuilder::for(SlugHistory::class, $request)
            ->allowedFilters(AllowedFilter::callback('type', static function (Builder $query, mixed $value): void {
                if (! is_string($value) || preg_match('/^[a-z_]{1,64}$/', $value) !== 1) {
                    throw new BadRequestHttpException('filter[type] must be an entity type.');
                }
                $query->where('sluggable_type', $value);
            }))
            ->allowedSorts('created_at')
            ->defaultSort('-created_at')
            ->orderBy('id', 'desc')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return SlugHistoryResource::collection($rows);
    }
}
