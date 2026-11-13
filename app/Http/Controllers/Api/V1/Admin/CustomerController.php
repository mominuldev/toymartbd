<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Customer\Enums\CustomerSegment;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerNote;
use App\Domain\Customer\Support\SegmentThresholds;
use App\Domain\Ordering\Models\Order;
use App\Domain\Reporting\Queries\Customers\CustomerDirectory;
use App\Domain\Reporting\Queries\Customers\CustomerLedgerValues;
use App\Domain\Risk\Models\CustomerRiskProfile;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Reports\ReportQuery;
use App\Http\Resources\Admin\Customers\CustomerListItemResource;
use App\Http\Resources\Admin\Customers\CustomerProfile;
use App\Http\Resources\Admin\Customers\CustomerProfileResource;
use App\Support\DhakaDate;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Admin Customers (Figma B · 17 241:40845, B · 18 241:41374; PRD U-01, U-02, U-05 read
 * side). Read-only here: customers are never deleted (they own orders, rule 12), and edits
 * arrive with the profile's write actions. Notes: {@see CustomerNoteController}.
 */
final class CustomerController extends ApiController
{
    public const int PER_PAGE = 25;

    public const int PROFILE_ORDERS = 20;

    private const array FILTERS = ['search', 'segment', 'has_account', 'risk', 'last_order_from', 'last_order_to', 'district_id'];

    /**
     * `GET /customers` — needs customers.view. Page-numbered (`page`), 25 a page. The phone
     * is always masked in the list (rule 20). `meta.counts` holds the customers per segment
     * plus `blacklisted` and `all`, under every filter except `segment` (the stat tiles);
     * `meta.thresholds` the segment thresholds in force (placeholders until the owner sets
     * them, config/customers.php).
     *
     * Anything not listed below is a 400 (rule 16).
     */
    #[QueryParameter('filter[search]', 'Name, 4+ phone digits, or an order number (prefix).', type: 'string')]
    #[QueryParameter('filter[segment]', 'no_orders|lapsed|at_risk|vip|new|active', type: 'string')]
    #[QueryParameter('filter[has_account]', '1: storefront account (phone verified by OTP); 0: guest only.', type: 'string')]
    #[QueryParameter('filter[risk]', 'Risk list status: normal|watch|blacklist|whitelist.', type: 'string')]
    #[QueryParameter('filter[last_order_from]', 'Last order on or after this Dhaka date (Y-m-d).', type: 'string', format: 'date')]
    #[QueryParameter('filter[last_order_to]', 'Last order on or before this Dhaka date (Y-m-d).', type: 'string', format: 'date')]
    #[QueryParameter('filter[district_id]', 'Ordered to, or has a saved address in, this district.', type: 'int')]
    #[QueryParameter('sort', 'Sort field; prefix `-` for descending.', type: "'last_order'|'-last_order'|'lifetime_value'|'-lifetime_value'|'orders_count'|'-orders_count'|'created'|'-created'", default: '-last_order')]
    #[QueryParameter('page', 'Page number, from 1.', type: 'int', default: 1)]
    #[OpenApiResponse(400, 'Unlisted parameter, filter or sort, or an invalid value (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing customers.view (`forbidden`)')]
    public function index(Request $request, CustomerDirectory $directory): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Customer::class);

        $query = ReportQuery::from($request, self::FILTERS, ['sort', 'page']);
        $filters = [
            'search' => $query->search(),
            'segment' => $query->filterIn('segment', CustomerSegment::values()),
            'has_account' => self::flag($request),
            'risk' => $query->filterIn('risk', CustomerDirectory::RISK_STATUSES),
            'last_order_from' => ($from = $query->date('last_order_from')) === null ? null : DhakaDate::utcBounds($from)[0],
            'last_order_to' => ($to = $query->date('last_order_to')) === null ? null : DhakaDate::utcBounds($to)[1],
            'district_id' => $query->id('district_id'),
        ];
        $sort = $query->sort(CustomerDirectory::SORTS, '-last_order');
        $page = $request->query('page', '1');
        if (! is_string($page) || ! ctype_digit($page) || (int) $page < 1) {
            throw new BadRequestHttpException('`page` must be a positive integer.');
        }

        $customers = $directory->paginate($filters, $sort, self::PER_PAGE, (int) $page)->withQueryString();
        $thresholds = SegmentThresholds::fromConfig();

        return CustomerListItemResource::collection($customers)->additional(['meta' => [
            'counts' => $directory->counts($filters),
            'thresholds' => [
                'vip_min_lifetime_value' => $thresholds->vipMinLifetimeValue->toDecimal(),
                'at_risk_after_days' => $thresholds->atRiskAfterDays,
                'lapsed_after_days' => $thresholds->lapsedAfterDays,
            ],
        ]]);
    }

    /**
     * `GET /customers/{customer}` — needs customers.view. Full phone, e-mail and street
     * addresses only with customers.view_pii (rule 20); `risk` only with risk.view. The 20
     * latest orders are listed; the order board filters by `filter[customer_id]` for the rest.
     */
    public function show(Customer $customer): CustomerProfileResource
    {
        $this->authorize('view', $customer);

        $customer->load(['addresses.district', 'addresses.upazila', 'addresses.division']);
        $statusCounts = Order::query()
            ->where('customer_id', $customer->id)
            ->toBase()
            ->select('status')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $n): int => (int) $n)
            ->all();
        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->with('items')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::PROFILE_ORDERS)
            ->get();
        $ledger = CustomerLedgerValues::forCustomer($customer->id);
        $segment = SegmentThresholds::fromConfig()->classify($customer->orders_count, $customer->last_order_at, $ledger['lifetime_value']);
        $notes = CustomerNote::query()->where('customer_id', $customer->id);

        return new CustomerProfileResource(new CustomerProfile(
            $customer,
            $statusCounts,
            $ledger,
            $segment->value,
            $orders,
            CustomerRiskProfile::forPhone($customer->phone),
            (clone $notes)->count(),
            (clone $notes)->where('is_pinned', true)->orderByDesc('id')->first(),
        ));
    }

    private static function flag(Request $request): ?bool
    {
        $filter = $request->query('filter');
        $value = is_array($filter) ? ($filter['has_account'] ?? null) : null;
        if ($value === null || $value === '') {
            return null;
        }

        return match ($value) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => throw new BadRequestHttpException('filter[has_account] must be 1 or 0.'),
        };
    }
}
