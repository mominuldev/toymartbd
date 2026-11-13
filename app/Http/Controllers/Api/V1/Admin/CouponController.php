<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Marketing\Actions\CreateCoupon;
use App\Domain\Marketing\Actions\DeactivateCoupon;
use App\Domain\Marketing\Actions\GenerateCouponCodes;
use App\Domain\Marketing\Actions\UpdateCoupon;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Ordering\Queries\MarketingUsageReport;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Coupon\GenerateCouponsRequest;
use App\Http\Requests\Admin\Coupon\StoreCouponRequest;
use App\Http\Requests\Admin\Coupon\UpdateCouponRequest;
use App\Http\Resources\Admin\CouponResource;
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
 * Coupons (P4-CRT-04) and the coupon builder (P7: bulk codes, usage report). `DELETE`
 * deactivates: a coupon is never hard-deleted, because its usages carry the per-customer limits.
 */
final class CouponController extends ApiController
{
    public const int PER_PAGE = 25;

    /**
     * `GET /coupons` — cursor-paginated, needs marketing.view.
     *
     * Filters: `filter[search]` (code contains), `filter[status]` = `active|inactive`.
     * Sorts: `code`, `created_at`, `ends_at` (prefix `-` for descending; default `-created_at`).
     */
    #[QueryParameter('filter[search]', 'Case-insensitive "contains" match on the code.', type: 'string')]
    #[QueryParameter('filter[status]', 'Only active or only inactive coupons.', type: "'active'|'inactive'")]
    #[QueryParameter('filter[batch]', 'Only the codes of one bulk batch.', type: 'string')]
    #[QueryParameter('sort', 'Sort field; prefix `-` for descending.', type: "'code'|'-code'|'created_at'|'-created_at'|'ends_at'|'-ends_at'", default: '-created_at')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor` / `meta.prev_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or sort, or an invalid filter value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Coupon::class);

        $sort = $request->query('sort', '-created_at');
        $descending = is_string($sort) && str_starts_with($sort, '-');

        $coupons = QueryBuilder::for(Coupon::class, $request)
            ->allowedFilters(
                AllowedFilter::callback('search', static function (Builder $query, mixed $value): void {
                    $term = is_array($value) ? implode(',', array_map(strval(...), $value)) : (string) $value;
                    $query->where('code', 'like', '%'.addcslashes(Coupon::normaliseCode($term), '\\%_').'%');
                }),
                AllowedFilter::callback('batch', static function (Builder $query, mixed $value): void {
                    $query->where('batch', Coupon::normaliseCode(is_string($value) ? $value : ''));
                }),
                AllowedFilter::callback('status', static function (Builder $query, mixed $value): void {
                    if (! in_array($value, ['active', 'inactive'], true)) {
                        throw new BadRequestHttpException('filter[status] must be one of: active, inactive.');
                    }
                    $query->where('is_active', $value === 'active');
                }),
            )
            ->allowedSorts('code', 'created_at', 'ends_at')
            ->defaultSort('-created_at')
            ->orderBy('id', $descending ? 'desc' : 'asc')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return CouponResource::collection($coupons);
    }

    /** `POST /coupons` — needs marketing.manage. */
    public function store(StoreCouponRequest $request, CreateCoupon $createCoupon): JsonResponse
    {
        $coupon = $createCoupon->handle($request->toData(), $request->user()?->getAuthIdentifier());

        return (new CouponResource($coupon))->response()->setStatusCode(201);
    }

    /**
     * `POST /coupons/generate` — `count` unique codes on the same terms, one batch (P7).
     * Needs marketing.manage. Answers `{batch, count, codes: list<string>}`.
     */
    public function generate(GenerateCouponsRequest $request, GenerateCouponCodes $generate): JsonResponse
    {
        $coupons = $generate->handle(
            $request->toData(),
            $request->integer('count'),
            $request->string('prefix')->toString(),
            $request->has('code_length') ? $request->integer('code_length') : 8,
            $request->string('batch')->toString(),
            $request->user()?->getAuthIdentifier(),
        );

        return response()->json([
            'batch' => $request->string('batch')->toString(),
            'count' => count($coupons),
            'codes' => array_map(static fn (Coupon $c): string => $c->code, $coupons),
        ], 201);
    }

    /**
     * `GET /coupons/{coupon}/report` — usage summary (orders, discount given, gross sales,
     * delivered revenue) and the 50 latest uses with masked phones. Needs marketing.view.
     * With a batch, `batch` sums every code of it.
     *
     * @return array{summary: array{orders: int, discount_total: string, gross_sales: string, delivered_orders: int, delivered_revenue: string}, batch: array{orders: int, discount_total: string, gross_sales: string, delivered_orders: int, delivered_revenue: string}|null, recent: list<array{order_id: int, order_number: string, status: string, phone: string, discount: string, grand_total: string, used_at: string}>}
     */
    public function report(Coupon $coupon, MarketingUsageReport $reports): array
    {
        $this->authorize('view', $coupon);
        $report = $reports->forCoupon($coupon->id);

        return [
            'summary' => $report['summary'],
            'batch' => $coupon->batch === null ? null : $reports->forBatch($coupon->batch),
            'recent' => $report['recent'],
        ];
    }

    /** `GET /coupons/{coupon}` — needs marketing.view. */
    public function show(Coupon $coupon): CouponResource
    {
        $this->authorize('view', $coupon);

        return new CouponResource($coupon);
    }

    /** `PUT /coupons/{coupon}` — replace the coupon's terms; needs marketing.manage. */
    public function update(UpdateCouponRequest $request, Coupon $coupon, UpdateCoupon $updateCoupon): CouponResource
    {
        return new CouponResource($updateCoupon->handle($coupon, $request->toData()));
    }

    /** `DELETE /coupons/{coupon}` — deactivates (idempotent); needs marketing.manage. */
    public function destroy(Coupon $coupon, DeactivateCoupon $deactivateCoupon): CouponResource
    {
        $this->authorize('delete', $coupon);

        return new CouponResource($deactivateCoupon->handle($coupon));
    }
}
