<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries\Customers;

use App\Domain\Customer\Enums\CustomerSegment;
use App\Domain\Customer\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The admin Customers list (Figma B · 17 241:40845; checklist §2.8). Read-only. Each row
 * carries the customer's aggregates, the ledger lifetime value (`ltv_value`), the computed
 * segment (`segment_value`), our risk score and list status (`risk_score_value`,
 * `risk_status_value`, from `customer_risk_profiles`, keyed on phone) and the district of
 * the latest order (`district_value`). Soft-deleted customers never appear; nothing is
 * ever deleted from here.
 *
 * Filters and sorts are validated by the caller against an allowlist (rule 16).
 */
final readonly class CustomerDirectory
{
    public const array SORTS = ['last_order', 'lifetime_value', 'orders_count', 'created'];

    public const array RISK_STATUSES = ['normal', 'watch', 'blacklist', 'whitelist'];

    /**
     * @param  array{search?: string|null, segment?: string|null, has_account?: bool|null, risk?: string|null, last_order_from?: CarbonImmutable|null, last_order_to?: CarbonImmutable|null, district_id?: int|null}  $filters
     *                                                                                                                                                                                                                          `last_order_to` is an exclusive UTC instant
     * @return LengthAwarePaginator<int, Customer>
     */
    public function paginate(array $filters, string $sort, int $perPage, int $page, ?CarbonImmutable $now = null): LengthAwarePaginator
    {
        return $this->query($filters, $now)
            ->tap(fn (Builder $q) => $this->sort($q, $sort))
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Customers per segment, plus `blacklisted` (risk list status) and `all`, under the
     * search and every filter except `segment` — the list's stat tiles.
     *
     * @param  array{search?: string|null, segment?: string|null, has_account?: bool|null, risk?: string|null, last_order_from?: CarbonImmutable|null, last_order_to?: CarbonImmutable|null, district_id?: int|null}  $filters
     * @return array<string, int>
     */
    public function counts(array $filters, ?CarbonImmutable $now = null): array
    {
        unset($filters['segment']);
        $rows = DB::query()
            ->fromSub($this->query($filters, $now)->toBase(), 'c')
            ->select('c.segment_value')
            ->selectRaw('COUNT(*) as aggregate')
            ->selectRaw("SUM(CASE WHEN c.risk_status_value = 'blacklist' THEN 1 ELSE 0 END) as blacklisted")
            ->groupBy('c.segment_value')
            ->get();

        $counts = ['all' => 0, 'blacklisted' => 0];
        foreach (CustomerSegment::cases() as $segment) {
            $counts[$segment->value] = 0;
        }
        foreach ($rows as $row) {
            $counts[(string) $row->segment_value] = (int) $row->aggregate;
            $counts['all'] += (int) $row->aggregate;
            $counts['blacklisted'] += (int) $row->blacklisted;
        }

        return $counts;
    }

    /**
     * @param  array{search?: string|null, segment?: string|null, has_account?: bool|null, risk?: string|null, last_order_from?: CarbonImmutable|null, last_order_to?: CarbonImmutable|null, district_id?: int|null}  $filters
     * @return Builder<Customer>
     */
    public function query(array $filters, ?CarbonImmutable $now = null): Builder
    {
        [$segmentSql, $segmentBindings] = CustomerSegmentSql::make($now)->expression();

        $latestDistrict = DB::table('orders as lo')
            ->select('lo.shipping_district')
            ->whereColumn('lo.customer_id', 'customers.id')
            ->orderByDesc('lo.created_at')
            ->orderByDesc('lo.id')
            ->limit(1);

        $query = Customer::query()
            ->leftJoinSub(CustomerLedgerValues::subquery(), 'ltv', 'ltv.customer_id', '=', 'customers.id')
            ->leftJoin('customer_risk_profiles as rp', 'rp.phone', '=', 'customers.phone')
            ->select('customers.*')
            ->selectRaw('COALESCE(ltv.lifetime_value, 0) as ltv_value')
            ->selectRaw("{$segmentSql} as segment_value", $segmentBindings)
            ->selectRaw('rp.internal_score as risk_score_value')
            ->selectRaw('rp.status as risk_status_value')
            ->selectSub($latestDistrict, 'district_value');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $this->search($query, $search);
        }

        $segment = $filters['segment'] ?? null;
        if ($segment !== null) {
            $query->whereRaw("({$segmentSql}) = ?", [...$segmentBindings, $segment]);
        }

        $account = $filters['has_account'] ?? null;
        if ($account !== null) {
            $account ? $query->whereNotNull('customers.phone_verified_at') : $query->whereNull('customers.phone_verified_at');
        }

        $risk = $filters['risk'] ?? null;
        if ($risk !== null) {
            $risk === 'normal'
                ? $query->where(static fn (Builder $q) => $q->whereNull('rp.status')->orWhere('rp.status', 'normal'))
                : $query->where('rp.status', $risk);
        }

        if (($from = $filters['last_order_from'] ?? null) !== null) {
            $query->where('customers.last_order_at', '>=', $from->utc()->format('Y-m-d H:i:s'));
        }
        if (($to = $filters['last_order_to'] ?? null) !== null) {
            $query->where('customers.last_order_at', '<', $to->utc()->format('Y-m-d H:i:s'));
        }

        if (($district = $filters['district_id'] ?? null) !== null) {
            $query->where(static function (Builder $q) use ($district): void {
                $q->whereExists(static fn (QueryBuilder $o) => $o->from('orders')->whereColumn('orders.customer_id', 'customers.id')->where('orders.shipping_district_id', $district))
                    ->orWhereExists(static fn (QueryBuilder $a) => $a->from('addresses')->whereColumn('addresses.customer_id', 'customers.id')->where('addresses.district_id', $district));
            });
        }

        return $query;
    }

    /**
     * Name, phone digits (4+, anywhere in the number) or an order number prefix.
     *
     * @param  Builder<Customer>  $query
     */
    private function search(Builder $query, string $term): void
    {
        $term = mb_substr($term, 0, 100);
        $digits = preg_replace('/\D/', '', $term) ?? '';
        // A national 01XXXXXXXXX or 8801… spelling: match on the part after the trunk prefix.
        $national = preg_replace('/^(?:880|0)/', '', $digits) ?? $digits;

        $query->where(static function (Builder $q) use ($term, $national, $digits): void {
            $q->where('customers.name', 'like', '%'.addcslashes($term, '\\%_').'%')
                ->orWhereExists(static fn (QueryBuilder $o) => $o->from('orders')
                    ->whereColumn('orders.customer_id', 'customers.id')
                    ->where('orders.number', 'like', addcslashes(strtoupper($term), '\\%_').'%'));
            if (strlen($digits) >= 4 && $national !== '') {
                $q->orWhere('customers.phone', 'like', '%'.$national.'%');
            }
        });
    }

    /** @param  Builder<Customer>  $query */
    private function sort(Builder $query, string $sort): void
    {
        $descending = str_starts_with($sort, '-');
        $direction = $descending ? 'desc' : 'asc';
        $column = match (ltrim($sort, '-')) {
            'lifetime_value' => 'ltv_value',
            'orders_count' => 'customers.orders_count',
            'created' => 'customers.created_at',
            default => 'customers.last_order_at',
        };

        $query->orderBy($column, $direction)->orderBy('customers.id', $direction);
    }
}
