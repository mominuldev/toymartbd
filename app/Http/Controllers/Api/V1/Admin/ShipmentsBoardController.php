<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\CourierTrackingEvent;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Reports\ShipmentsBoard;
use App\Domain\Ordering\Enums\ReturnStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderReturn;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Reports\ReportQuery;
use App\Http\Resources\Admin\ShipmentsBoard\ShipmentBoardIssuesResource;
use App\Http\Resources\Admin\ShipmentsBoard\ShipmentBoardRowResource;
use App\Http\Resources\Admin\ShipmentsBoard\ShipmentBoardStatsResource;
use App\Http\Resources\Admin\ShipmentsBoard\ShipmentTrackingResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Shipments & tracking board (Figma B · 08, docs/api/shipments-board.md). Read-only; every
 * route needs shipments.view. Figures come from {@see ShipmentsBoard} (rule 1); the customer
 * phone is masked without customers.view_pii (rule 20). Query strings are allowlisted —
 * an unlisted parameter, filter or value is a 400 (rule 16).
 */
final class ShipmentsBoardController extends ApiController
{
    public const int PER_PAGE = 25;

    /**
     * `GET /shipments/board` — the shipments handed to a courier in the range, newest first,
     * cursor-paginated. `meta.counts` holds one count per tab under the other filters;
     * `meta.providers` the courier filter's options.
     */
    #[QueryParameter('filter[tab]', 'all (default) | submitted | in_transit | delivered | returned | failed_attempts', type: 'string')]
    #[QueryParameter('filter[provider]', 'Only this courier (`courier_providers.code`).', type: 'string')]
    #[QueryParameter('filter[from]', 'First Dhaka date handed to the courier (inclusive). Default: six days before today.', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'Last Dhaka date (inclusive). Default: today.', type: 'string', format: 'date')]
    #[QueryParameter('filter[search]', 'Shipment number, order number or consignment id (prefix).', type: 'string')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor` / `meta.prev_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted parameter or filter, an unknown tab or provider, or a malformed date (`bad-request`)')]
    public function index(Request $request, ShipmentsBoard $board): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Shipment::class);
        $query = ReportQuery::from($request, ['tab', 'provider', 'from', 'to', 'search'], ['cursor']);
        $tab = $query->filterIn('tab', ShipmentsBoard::TABS) ?? 'all';
        $period = $query->period();

        $base = ShipmentsBoard::cohort(Shipment::query(), $period->fromDate(), $period->toDate());
        $provider = self::provider($query);
        if ($provider !== null) {
            $base->where('shipments.provider_id', $provider->id);
        }
        $search = $query->search();
        if ($search !== null) {
            $like = addcslashes($search, '\\%_').'%';
            $base->where(static fn (Builder $q) => $q
                ->where('shipments.number', 'like', $like)
                ->orWhere('shipments.consignment_id', 'like', $like)
                ->orWhereIn('shipments.order_id', DB::table('orders')->where('number', 'like', $like)->select('id')));
        }

        $counts = ShipmentsBoard::tabCounts($base);

        /** @var CursorPaginator<int, Shipment> $page */
        $page = ShipmentsBoard::tab($base->clone(), $tab)
            ->with('provider')
            ->orderByDesc('shipments.id')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        $rows = collect($page->items());
        self::attachOrders($rows);
        $events = ShipmentsBoard::lastEvents($rows->pluck('id')->all());
        $costs = $board->costs($rows);
        foreach ($rows as $s) {
            $s->setRelation('boardLastEvent', $events[$s->id] ?? null);
            $s->setRelation('boardCost', (object) $costs[$s->id]);
        }

        return ShipmentBoardRowResource::collection($page)->additional(['meta' => [
            'counts' => $counts,
            'from' => $period->fromDate(),
            'to' => $period->toDate(),
            /** @var list<array{code: string, name: string, name_bn: string|null}> the courier filter's options */
            'providers' => CourierProvider::query()->orderBy('position')->orderBy('name')->get(['code', 'name', 'name_bn'])
                ->map(static fn (CourierProvider $p): array => ['code' => $p->code, 'name' => $p->name, 'name_bn' => $p->name_bn])->values()->all(),
        ]]);
    }

    /** `GET /shipments/board/stats` — the stat tiles. In transit / submitted are live; the rest are outcome-dated in the range. */
    #[QueryParameter('filter[from]', 'First Dhaka date (inclusive). Default: six days before today.', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'Last Dhaka date (inclusive). Default: today.', type: 'string', format: 'date')]
    #[QueryParameter('filter[provider]', 'Only this courier (`courier_providers.code`).', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted parameter or filter, an unknown provider or a malformed date (`bad-request`)')]
    public function stats(Request $request, ShipmentsBoard $board): ShipmentBoardStatsResource
    {
        $this->authorize('viewAny', Shipment::class);
        $query = ReportQuery::from($request, ['from', 'to', 'provider']);
        $period = $query->period();

        return new ShipmentBoardStatsResource($board->stats($period->fromDate(), $period->toDate(), self::provider($query)?->code));
    }

    /** `GET /shipments/board/issues` — delivery issues and success by courier × area, last 30 Dhaka days. */
    #[OpenApiResponse(400, 'Any query parameter (`bad-request`)')]
    public function issues(Request $request, ShipmentsBoard $board): ShipmentBoardIssuesResource
    {
        $this->authorize('viewAny', Shipment::class);
        ReportQuery::from($request, []);

        return new ShipmentBoardIssuesResource($board->issues());
    }

    /** `GET /shipments/{shipment}/tracking` — the detail card: charges, attempts, the courier timeline, the partial outcome. */
    #[OpenApiResponse(400, 'Any query parameter (`bad-request`)')]
    public function tracking(Request $request, Shipment $shipment, ShipmentsBoard $board): ShipmentTrackingResource
    {
        $this->authorize('view', $shipment);
        ReportQuery::from($request, []);

        $shipment->loadMissing('provider');
        self::attachOrders(collect([$shipment]), true);
        $shipment->setRelation('boardCost', (object) $board->costs(collect([$shipment]))[$shipment->id]);
        $shipment->setRelation('boardEvents', CourierTrackingEvent::query()->where('shipment_id', $shipment->id)
            ->orderByDesc('occurred_at')->orderByDesc('id')->get());
        $shipment->setRelation('boardReturns', OrderReturn::query()->where('order_id', $shipment->order_id)
            ->where('status', '!=', ReturnStatus::Rejected->value)
            ->with('items.orderItem')->orderBy('id')->get());

        return new ShipmentTrackingResource($shipment);
    }

    private static function provider(ReportQuery $query): ?CourierProvider
    {
        $code = $query->filterIn('provider');
        if ($code === null) {
            return null;
        }

        return CourierProvider::query()->where('code', $code)->first()
            ?? throw new BadRequestHttpException('filter[provider] is not a courier provider.');
    }

    /** @param  Collection<int, Shipment>  $shipments */
    private static function attachOrders(Collection $shipments, bool $withItems = false): void
    {
        $orders = Order::query()->with($withItems ? ['items'] : [])->whereIn('id', $shipments->pluck('order_id')->unique())->get()->keyBy('id');
        foreach ($shipments as $shipment) {
            $shipment->setRelation('orderSummary', $orders[$shipment->order_id] ?? null);
        }
    }
}
