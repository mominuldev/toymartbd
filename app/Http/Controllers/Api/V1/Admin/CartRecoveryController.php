<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Cart\Models\Cart;
use App\Domain\Customer\Models\Customer;
use App\Domain\Notification\Enums\CartRecoveryStatus;
use App\Domain\Notification\Models\CartRecovery;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Notification\Queries\CartRecoveryReport;
use App\Domain\Ordering\Models\Order;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Admin\CartRecoveryResource;
use App\Support\DhakaDate;
use App\Support\PhoneNumber;
use DateTimeImmutable;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Abandoned carts (P7, Figma B · 21 241:42726): the report and the list. Read-only,
 * marketing.view. Phones masked (rule 20); revenue counts delivered orders only.
 */
final class CartRecoveryController extends ApiController
{
    public const int PER_PAGE = 25;

    /**
     * `GET /cart-recoveries/summary?from=YYYY-MM-DD&to=YYYY-MM-DD` — Dhaka dates, default the
     * last 30 days, at most 366 days.
     *
     * @return array{from: string, to: string, abandoned: int, messaged: int, skipped: array<string, int>, sent: int, cost: string, restored: int, recovered: int, recovered_delivered: int, revenue_recovered: string, abandoned_value: string}
     */
    #[QueryParameter('from', 'First Dhaka date (Y-m-d).', type: 'string')]
    #[QueryParameter('to', 'Last Dhaka date (Y-m-d).', type: 'string')]
    #[OpenApiResponse(400, 'Bad date range (`bad-request`)')]
    public function summary(Request $request, CartRecoveryReport $report): array
    {
        $this->authorize('viewAny', CartRecovery::class);
        [$from, $to] = self::range($request);

        return $report->summary($from, $to);
    }

    /**
     * `GET /cart-recoveries` — newest abandoned first, cursor-paginated. Filters:
     * `filter[status]` = `messaged|skipped|recovered`.
     */
    #[QueryParameter('filter[status]', 'Messaged, skipped, or recovered (became an order).', type: "'messaged'|'skipped'|'recovered'")]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CartRecovery::class);

        $page = QueryBuilder::for(CartRecovery::class, $request)
            ->allowedFilters(AllowedFilter::callback('status', static function (Builder $query, mixed $value): void {
                match ($value) {
                    'messaged' => $query->where('status', CartRecoveryStatus::Messaged->value),
                    'skipped' => $query->where('status', CartRecoveryStatus::Skipped->value),
                    'recovered' => $query->whereNotNull('recovered_order_id'),
                    default => throw new BadRequestHttpException('filter[status] must be one of: messaged, skipped, recovered.'),
                };
            }))
            ->orderByDesc('abandoned_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        self::decorate(collect($page->items()));

        return CartRecoveryResource::collection($page);
    }

    /** @param  Collection<int, CartRecovery>  $rows */
    private static function decorate(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }
        $carts = Cart::query()->whereIn('id', $rows->pluck('cart_id'))->get(['id', 'phone', 'customer_id'])->keyBy('id');
        $customers = Customer::query()->whereIn('id', $rows->pluck('customer_id')->filter()->merge($carts->pluck('customer_id')->filter())->unique())
            ->get(['id', 'name', 'phone'])->keyBy('id');
        $logs = NotificationLog::query()->where('notifiable_type', CartRecovery::NOTIFIABLE)->whereIn('notifiable_id', $rows->pluck('id'))
            ->get()->keyBy('notifiable_id');
        $orders = Order::query()->whereIn('id', $rows->pluck('recovered_order_id')->filter())->get(['id', 'number', 'status', 'grand_total'])->keyBy('id');

        foreach ($rows as $row) {
            $cart = $carts->get($row->cart_id);
            $customer = $customers->get($row->customer_id ?? $cart?->customer_id);
            $log = $logs->get($row->id);
            $order = $row->recovered_order_id === null ? null : $orders->get($row->recovered_order_id);
            $row->setAttribute('masked_phone', PhoneNumber::tryParse((string) ($cart->phone ?? $customer->phone ?? ''))?->masked());
            $row->setAttribute('customer_name', $customer?->name);
            $row->setAttribute('message_status', $log?->status->value);
            $row->setAttribute('message_cost', $log?->cost->toDecimal());
            $row->setAttribute('message_segments', $log?->segments);
            $row->setAttribute('message_sent_at', $log?->sent_at?->utc()->format('Y-m-d\TH:i:s\Z'));
            $row->setAttribute('order_number', $order?->number);
            $row->setAttribute('order_status', $order?->status->value);
            $row->setAttribute('order_total', $order?->grand_total->toDecimal());
        }
    }

    /** @return array{string, string} */
    private static function range(Request $request): array
    {
        $today = DhakaDate::todayDate();
        $from = $request->query('from', $today->subDays(29)->toDateString());
        $to = $request->query('to', $today->toDateString());
        foreach ([$from, $to] as $date) {
            if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || DateTimeImmutable::createFromFormat('!Y-m-d', $date) === false) {
                throw new BadRequestHttpException('from and to must be Y-m-d dates.');
            }
        }
        if ($from > $to || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 366) {
            throw new BadRequestHttpException('from must not be after to, and the range is at most 366 days.');
        }

        return [$from, $to];
    }
}
