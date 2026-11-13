<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Marketing\Actions\DeactivatePromotion;
use App\Domain\Marketing\Actions\SavePromotion;
use App\Domain\Marketing\Enums\PromotionType;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Ordering\Queries\MarketingUsageReport;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Promotion\StorePromotionRequest;
use App\Http\Requests\Admin\Promotion\UpdatePromotionRequest;
use App\Http\Resources\Admin\PromotionResource;
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
 * Automatic promotions and flash sales (P7, docs/api/promotions.md). `DELETE` deactivates:
 * a promotion's usages carry its per-customer limits and its report.
 */
final class PromotionController extends ApiController
{
    public const int PER_PAGE = 25;

    /**
     * `GET /promotions` — cursor-paginated, needs marketing.view.
     *
     * Filters: `filter[search]` (name contains), `filter[type]`, `filter[status]` =
     * `scheduled|running|ended|inactive` (at request time). Sorts: `priority`, `starts_at`,
     * `ends_at`, `created_at` (default `-priority`).
     */
    #[QueryParameter('filter[search]', 'Name contains.', type: 'string')]
    #[QueryParameter('filter[type]', 'One promotion type.', type: "'discount'|'flash_sale'|'category_discount'|'tiered'|'buy_x_get_y'|'bundle'|'free_delivery'")]
    #[QueryParameter('filter[status]', 'Status now.', type: "'scheduled'|'running'|'ended'|'inactive'")]
    #[QueryParameter('sort', 'Sort field; prefix `-` for descending.', type: "'priority'|'-priority'|'starts_at'|'-starts_at'|'ends_at'|'-ends_at'|'created_at'|'-created_at'", default: '-priority')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor` / `meta.prev_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or sort, or an invalid filter value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Promotion::class);

        $sort = $request->query('sort', '-priority');
        $descending = is_string($sort) && str_starts_with($sort, '-');

        $promotions = QueryBuilder::for(Promotion::class, $request)
            ->allowedFilters(
                AllowedFilter::callback('search', static function (Builder $query, mixed $value): void {
                    $term = is_array($value) ? implode(',', array_map(strval(...), $value)) : (string) $value;
                    $query->where(fn (Builder $q) => $q->where('name', 'like', '%'.addcslashes($term, '\\%_').'%')
                        ->orWhere('name_bn', 'like', '%'.addcslashes($term, '\\%_').'%'));
                }),
                AllowedFilter::callback('type', static function (Builder $query, mixed $value): void {
                    if (! is_string($value) || PromotionType::tryFrom($value) === null) {
                        throw new BadRequestHttpException('filter[type] must be a promotion type.');
                    }
                    $query->where('type', $value);
                }),
                AllowedFilter::callback('status', static function (Builder $query, mixed $value): void {
                    $now = now();
                    match ($value) {
                        'inactive' => $query->where('is_active', false),
                        'scheduled' => $query->where('is_active', true)->where('starts_at', '>', $now),
                        'ended' => $query->where('is_active', true)->whereNotNull('ends_at')->where('ends_at', '<=', $now),
                        'running' => $query->where('is_active', true)
                            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now)),
                        default => throw new BadRequestHttpException('filter[status] must be one of: scheduled, running, ended, inactive.'),
                    };
                }),
            )
            ->allowedSorts('priority', 'starts_at', 'ends_at', 'created_at')
            ->defaultSort('-priority')
            ->with('flashItems.variant.product')
            ->orderBy('id', $descending ? 'desc' : 'asc')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return PromotionResource::collection($promotions);
    }

    /** `POST /promotions` — needs marketing.manage. */
    public function store(StorePromotionRequest $request, SavePromotion $save): JsonResponse
    {
        $promotion = $save->handle($request->toData(), null, $request->user()?->getAuthIdentifier());

        return (new PromotionResource($promotion))->response()->setStatusCode(201);
    }

    /** `GET /promotions/{promotion}` — needs marketing.view. */
    public function show(Promotion $promotion): PromotionResource
    {
        $this->authorize('view', $promotion);

        return new PromotionResource($promotion);
    }

    /** `PUT /promotions/{promotion}` — replace the whole document; needs marketing.manage. */
    public function update(UpdatePromotionRequest $request, Promotion $promotion, SavePromotion $save): PromotionResource
    {
        return new PromotionResource($save->handle($request->toData(), $promotion));
    }

    /** `DELETE /promotions/{promotion}` — deactivates (idempotent); needs marketing.manage. */
    public function destroy(Promotion $promotion, DeactivatePromotion $deactivate): PromotionResource
    {
        $this->authorize('delete', $promotion);

        return new PromotionResource($deactivate->handle($promotion));
    }

    /**
     * `GET /promotions/{promotion}/report` — orders, discount given, gross sales, delivered
     * revenue, and the 50 latest uses (masked phones). Needs marketing.view.
     *
     * @return array{summary: array{orders: int, discount_total: string, gross_sales: string, delivered_orders: int, delivered_revenue: string}, recent: list<array{order_id: int, order_number: string, status: string, phone: string, discount: string, grand_total: string, used_at: string}>}
     */
    public function report(Promotion $promotion, MarketingUsageReport $reports): array
    {
        $this->authorize('view', $promotion);

        return $reports->forPromotion($promotion->id);
    }
}
