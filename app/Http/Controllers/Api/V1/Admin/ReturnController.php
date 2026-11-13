<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ordering\Actions\Returns\InspectReturn;
use App\Domain\Ordering\Actions\Returns\ReceiveReturn;
use App\Domain\Ordering\Actions\Returns\RejectReturn;
use App\Domain\Ordering\Actions\Returns\RequestReturn;
use App\Domain\Ordering\Enums\ReturnStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderReturn;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Returns\InspectReturnRequest;
use App\Http\Requests\Admin\Returns\RejectReturnRequest;
use App\Http\Requests\Admin\Returns\ReturnWorkflowRequest;
use App\Http\Requests\Admin\Returns\StoreReturnRequest;
use App\Http\Resources\Admin\OrderRefundPanelResource;
use App\Http\Resources\Admin\OrderReturnResource;
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
 * Returns & refunds queue (Figma 211:5121, DATABASE-DESIGN §4.7, PRD O-21). Every step goes
 * through an Ordering Action; a step the return's state does not allow is a 409 `conflict`.
 */
final class ReturnController extends ApiController
{
    public const int PER_PAGE = 25;

    /**
     * `GET /returns` — cursor-paginated, newest first; needs returns.view. Filters:
     * `filter[status]` (requested|received|inspected|closed|rejected), `filter[search]`
     * (RMA or order number prefix). Anything else is a 400. `meta.counts` holds one count
     * per status, under the search filter only (for the tabs).
     */
    #[QueryParameter('filter[status]', 'requested|received|inspected|closed|rejected', type: 'string')]
    #[QueryParameter('filter[search]', 'RMA or order number prefix.', type: 'string')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or an invalid value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', OrderReturn::class);

        $returns = QueryBuilder::for(OrderReturn::class, $request)
            ->allowedFilters(...self::filters())
            ->allowedSorts('created_at')
            ->defaultSort('-created_at')
            ->orderByDesc('id')
            ->with(OrderReturnResource::RELATIONS)
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return OrderReturnResource::collection($returns)->additional(['meta' => ['counts' => $this->counts($request)]]);
    }

    /** `GET /returns/{orderReturn}` — needs returns.view. */
    public function show(OrderReturn $orderReturn): OrderReturnResource
    {
        $this->authorize('view', $orderReturn);

        return new OrderReturnResource($orderReturn->load(OrderReturnResource::RELATIONS));
    }

    /** `GET /orders/{order}/returns` — the order detail's Refund panel. Needs returns.view. */
    public function forOrder(Order $order): OrderRefundPanelResource
    {
        $this->authorize('viewAny', OrderReturn::class);

        return new OrderRefundPanelResource($order->load('items'));
    }

    /**
     * `POST /orders/{order}/returns` — open a return on a delivered order. Needs
     * returns.manage. A quantity above what is still returnable is a 422; an order that is
     * not delivered is a 409.
     */
    #[OpenApiResponse(409, 'The order is not delivered or partially returned (`conflict`)')]
    public function store(StoreReturnRequest $request, Order $order, RequestReturn $action): JsonResponse
    {
        $return = $action->handle($order, $request->lines(), $request->reason(), $request->reasonNote(), $request->boolean('refund_shipping'), $request->user()?->id);

        return (new OrderReturnResource($return->load(OrderReturnResource::RELATIONS)))->response()->setStatusCode(201);
    }

    /** `POST /returns/{orderReturn}/receive` — the parcel is back. Needs returns.manage. */
    #[OpenApiResponse(409, 'The return is not requested any more (`conflict`)')]
    public function receive(ReturnWorkflowRequest $request, OrderReturn $orderReturn, ReceiveReturn $action): OrderReturnResource
    {
        return $this->fresh($action->handle($orderReturn));
    }

    /**
     * `POST /returns/{orderReturn}/inspect` — resellable / damaged per line. Restocks,
     * writes damaged units off, moves the order to partially_returned / returned and opens
     * the server-computed refund. Needs returns.manage.
     */
    #[OpenApiResponse(409, 'The return was already inspected or rejected (`conflict`)')]
    public function inspect(InspectReturnRequest $request, OrderReturn $orderReturn, InspectReturn $action): OrderReturnResource
    {
        return $this->fresh($action->handle($orderReturn, $request->inspection(), $request->user()?->id, $request->note()));
    }

    /** `POST /returns/{orderReturn}/reject` — before inspection, with a reason. Needs returns.manage. */
    #[OpenApiResponse(409, 'The return was already inspected (`conflict`)')]
    public function reject(RejectReturnRequest $request, OrderReturn $orderReturn, RejectReturn $action): OrderReturnResource
    {
        return $this->fresh($action->handle($orderReturn, $request->note()));
    }

    private function fresh(OrderReturn $return): OrderReturnResource
    {
        return new OrderReturnResource($return->refresh()->load(OrderReturnResource::RELATIONS));
    }

    /** @return array<string, int> */
    private function counts(Request $request): array
    {
        /** @var array<string, mixed> $filter */
        $filter = is_array($request->query('filter')) ? $request->query('filter') : [];
        unset($filter['status']);
        $rows = QueryBuilder::for(OrderReturn::class, Request::create('/', 'GET', ['filter' => $filter]))
            ->allowedFilters(...self::filters())
            ->toBase()
            ->select('status')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = [];
        foreach (ReturnStatus::cases() as $status) {
            $counts[$status->value] = (int) ($rows[$status->value] ?? 0);
        }

        return $counts;
    }

    /** @return list<AllowedFilter> */
    private static function filters(): array
    {
        return [
            AllowedFilter::callback('status', static function (Builder $query, mixed $value): void {
                $status = is_string($value) ? ReturnStatus::tryFrom($value) : null;
                if ($status === null) {
                    throw new BadRequestHttpException('filter[status] must be one of: '.implode(', ', ReturnStatus::values()).'.');
                }
                $query->where('status', $status->value);
            }),
            AllowedFilter::callback('search', static function (Builder $query, mixed $value): void {
                $term = strtoupper(trim(is_string($value) ? $value : ''));
                if ($term === '') {
                    return;
                }
                $like = addcslashes($term, '\\%_').'%';
                $query->where(static fn (Builder $q) => $q->where('number', 'like', $like)
                    ->orWhereHas('order', static fn (Builder $o) => $o->where('number', 'like', $like)));
            }),
        ];
    }
}
