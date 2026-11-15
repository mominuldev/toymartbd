<?php

declare(strict_types=1);

namespace App\Domain\Pos\Queries;

use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Enums\OrderSource;
use App\Domain\Pos\Services\PosCustomers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Find a customer at the counter (POS F9 "Customer") by a name fragment or phone digits.
 *
 *  - Digits match anywhere in the stored E.164 number after the country code and trunk zero
 *    are dropped from what was typed (`01819…`, `+8801819…`, `552310` all work). Nothing is
 *    inferred from a prefix (rule 30: no operator guessing).
 *  - Only customers who have bought at the POS (an order with source `pos`) — online-only
 *    shoppers stay out of the counter list (owner request, 2026-11-15).
 *  - An empty term lists the most recent customers, so the dialog opens on a list.
 *  - Never the anonymous walk-in row, never a trashed customer. At most {@see self::LIMIT},
 *    most recent order first. The term is never logged (rule 20).
 *
 * A name match is a `%term%` LIKE — no index can serve it; the customer table is small enough
 * at launch and the result is capped. The phone column is already uniquely indexed.
 */
final readonly class PosCustomerSearch
{
    public const int LIMIT = 8;

    /** @return Collection<int, Customer> */
    public function search(string $term): Collection
    {
        $term = trim($term);
        $digits = preg_replace('/\D+/', '', $term) ?? '';
        $isPhone = $digits !== '' && preg_match('/^[\d\s+\-()]+$/', $term) === 1;
        if ($isPhone) {
            $digits = (string) preg_replace('/^(?:880|0)/', '', $digits);
        }

        $query = Customer::query()
            ->where('phone', '!=', PosCustomers::WALK_IN_PHONE)
            ->whereExists(fn (QueryBuilder $orders) => $orders->selectRaw('1')->from('orders')
                ->whereColumn('orders.customer_id', 'customers.id')
                ->where('orders.source', OrderSource::Pos->value));
        if ($term === '') {
            return $this->recent($query);
        }
        if ($isPhone) {
            if (strlen($digits) < 2) {
                return new Collection;
            }
            $query->where('phone', 'like', '%'.$digits.'%');
        } else {
            $query->where('name', 'like', '%'.addcslashes($term, '%_\\').'%');
        }

        return $this->recent($query);
    }

    /**
     * Most recent order first, customers who never ordered last, capped.
     *
     * @param  Builder<Customer>  $query
     * @return Collection<int, Customer>
     */
    private function recent(Builder $query): Collection
    {
        return $query->orderByRaw('CASE WHEN last_order_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('last_order_at')->orderByDesc('id')
            ->limit(self::LIMIT)->get();
    }
}
