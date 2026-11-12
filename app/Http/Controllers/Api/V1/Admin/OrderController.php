<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ordering\Enums\FulfillmentStatus;
use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Domain\Ordering\Enums\OrderSource;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\RiskLevel;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Admin\OrderListItemResource;
use App\Http\Resources\Admin\OrderResource;
use App\Support\DhakaDate;
use BackedEnum;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use InvalidArgumentException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Admin order board and detail (P4-ORD-04/05, read side). Orders are never deleted
 * (rule 12); writes (confirm, cancel…) arrive with the board's actions.
 */
final class OrderController extends ApiController
{
    public const int PER_PAGE = 25;

    /**
     * `GET /orders` — cursor-paginated, needs orders.view. `meta.counts` holds one count
     * per status (plus `all`) under every filter except `status`, for the board's tabs.
     *
     * Filters: `filter[status]`, `filter[payment_status]`, `filter[fulfillment_status]`,
     * `filter[source]`, `filter[risk_level]`, `filter[payment_method]` (enum values),
     * `filter[customer_id]`, `filter[placed_from]` / `filter[placed_to]` (Y-m-d Dhaka dates,
     * inclusive), `filter[search]` (order number, the last 4+ phone digits, or the name).
     * Sorts: `created_at`, `grand_total`, each with `-` for descending (default
     * `-created_at`). Anything else is a 400.
     */
    #[QueryParameter('filter[status]', 'Only orders in this status (the board tab).', type: 'string')]
    #[QueryParameter('filter[payment_status]', 'Only orders with this payment status.', type: 'string')]
    #[QueryParameter('filter[fulfillment_status]', 'Only orders with this fulfilment status.', type: 'string')]
    #[QueryParameter('filter[source]', 'web|admin|phone|facebook|whatsapp', type: 'string')]
    #[QueryParameter('filter[risk_level]', 'low|medium|high', type: 'string')]
    #[QueryParameter('filter[payment_method]', 'cod|sslcommerz|bkash|nagad|card|bank_transfer|cash', type: 'string')]
    #[QueryParameter('filter[customer_id]', 'Only this customer\'s orders.', type: 'int')]
    #[QueryParameter('filter[placed_from]', 'First Dhaka date (Y-m-d), inclusive.', type: 'string')]
    #[QueryParameter('filter[placed_to]', 'Last Dhaka date (Y-m-d), inclusive.', type: 'string')]
    #[QueryParameter('filter[search]', 'Order number, last 4+ phone digits, or customer name.', type: 'string')]
    #[QueryParameter('sort', 'Sort field; prefix `-` for descending.', type: "'created_at'|'-created_at'|'grand_total'|'-grand_total'", default: '-created_at')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor` / `meta.prev_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or sort, or an invalid filter value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Order::class);

        $sort = $request->query('sort');
        $descending = ! is_string($sort) || str_starts_with($sort, '-');

        $orders = QueryBuilder::for(Order::class, $request)
            ->allowedFilters(...self::filters())
            ->allowedSorts('created_at', 'grand_total')
            ->defaultSort('-created_at')
            ->orderBy('id', $descending ? 'desc' : 'asc')
            ->with(OrderListItemResource::RELATIONS)
            ->withCount('items')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return OrderListItemResource::collection($orders)->additional(['meta' => ['counts' => $this->counts($request)]]);
    }

    /**
     * `GET /orders/{order}` — needs orders.view. Full phone and address only with
     * customers.view_pii; item cost only with catalog.view_cost.
     */
    public function show(Order $order): OrderResource
    {
        $this->authorize('view', $order);

        return new OrderResource($order->load(OrderResource::RELATIONS));
    }

    /** @return array<string, int> status → count, plus `all`, under every filter but status */
    private function counts(Request $request): array
    {
        /** @var array<string, mixed> $filter */
        $filter = is_array($request->query('filter')) ? $request->query('filter') : [];
        unset($filter['status']);
        $countRequest = Request::create('/', 'GET', ['filter' => $filter]);

        $rows = QueryBuilder::for(Order::class, $countRequest)
            ->allowedFilters(...self::filters())
            ->toBase()
            ->select('status')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = ['all' => (int) $rows->sum()];
        foreach (OrderStatus::cases() as $status) {
            $counts[$status->value] = (int) ($rows[$status->value] ?? 0);
        }

        return $counts;
    }

    /** @return list<AllowedFilter> */
    private static function filters(): array
    {
        return [
            self::enumFilter('status', OrderStatus::class),
            self::enumFilter('payment_status', OrderPaymentStatus::class),
            self::enumFilter('fulfillment_status', FulfillmentStatus::class),
            self::enumFilter('source', OrderSource::class),
            self::enumFilter('risk_level', RiskLevel::class),
            self::enumFilter('payment_method', PaymentMethod::class),
            AllowedFilter::callback('customer_id', static function (Builder $query, mixed $value): void {
                if (! is_string($value) || ! ctype_digit($value)) {
                    throw new BadRequestHttpException('filter[customer_id] must be a positive integer id.');
                }
                $query->where('customer_id', (int) $value);
            }),
            AllowedFilter::callback('placed_from', static function (Builder $query, mixed $value): void {
                $query->where('created_at', '>=', self::dhakaBounds('placed_from', $value)[0]);
            }),
            AllowedFilter::callback('placed_to', static function (Builder $query, mixed $value): void {
                $query->where('created_at', '<', self::dhakaBounds('placed_to', $value)[1]);
            }),
            AllowedFilter::callback('search', static function (Builder $query, mixed $value): void {
                $term = trim(is_string($value) ? $value : '');
                if ($term === '') {
                    return;
                }
                $digits = preg_replace('/\D/', '', $term) ?? '';
                $query->where(static function (Builder $q) use ($term, $digits): void {
                    $q->where('number', 'like', addcslashes(strtoupper($term), '\\%_').'%')
                        ->orWhere('shipping_name', 'like', '%'.addcslashes($term, '\\%_').'%');
                    if (strlen($digits) >= 4) {
                        $q->orWhere('shipping_phone', 'like', '%'.substr($digits, -10));
                    }
                });
            }),
        ];
    }

    /** @param  class-string<BackedEnum>  $enum */
    private static function enumFilter(string $name, string $enum): AllowedFilter
    {
        return AllowedFilter::callback($name, static function (Builder $query, mixed $value) use ($name, $enum): void {
            $case = is_string($value) ? $enum::tryFrom($value) : null;
            if ($case === null) {
                $allowed = implode(', ', array_map(static fn (BackedEnum $c): string => (string) $c->value, $enum::cases()));
                throw new BadRequestHttpException("filter[{$name}] must be one of: {$allowed}.");
            }
            $query->where($name, $case->value);
        });
    }

    /** @return array{0: \Carbon\CarbonImmutable, 1: \Carbon\CarbonImmutable} */
    private static function dhakaBounds(string $name, mixed $value): array
    {
        try {
            return DhakaDate::utcBounds(is_string($value) ? $value : '');
        } catch (InvalidArgumentException) {
            throw new BadRequestHttpException("filter[{$name}] must be a Y-m-d date.");
        }
    }
}
