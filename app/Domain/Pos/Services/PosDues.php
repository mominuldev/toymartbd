<?php

declare(strict_types=1);

namespace App\Domain\Pos\Services;

use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Enums\OrderSource;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Pos\DTOs\TenderPlan;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A customer's due (baki) at the counter — owner decision 2026-11-15. Due is the UNPAID PART
 * OF A POS ORDER: no ledger row and no table of its own holds it; `orders.due_total` does.
 *
 *   open due = Σ due_total of the customer's POS orders that were handed over (`delivered`)
 *              and still owe something (due_total > 0).
 *
 * Only POS orders count: a storefront COD order in transit also has due_total > 0, but the
 * courier collects that, never the counter — counting it would refuse credit for a parcel on
 * its way and let the counter take money the courier will also take.
 *
 * Guards on a due tender (the errors land on `tenders.{i}.method`):
 *   - the cashier holds `pos.sell_on_due` (decided by the caller — Domain never reads the session);
 *   - a known customer: a valid phone (rule 30) and a real name — the customer's own, or the
 *     sale's `customer_name` — never the anonymous walk-in row;
 *   - open due + this due ≤ the limit (PosDueSettings). ChargeSale re-checks this under the
 *     customer's row lock with a LOCKING read of the orders, so two concurrent sales for one
 *     customer cannot both pass on the same snapshot.
 */
final readonly class PosDues
{
    public function __construct(private PosDueSettings $settings) {}

    public function limit(): Money
    {
        return $this->settings->limit();
    }

    /** Owes on these orders, oldest first (FIFO for a collection; also the lock order). */
    public function openDue(int $customerId, bool $lock = false): Money
    {
        $query = self::open($customerId);
        if ($lock) {
            // A locking read sees the latest committed rows, not the transaction's snapshot.
            $ids = $query->orderBy('id')->lockForUpdate()->pluck('due_total', 'id');

            return $ids->reduce(static fn (Money $c, mixed $v): Money => $c->plus(self::money($v)), Money::zero());
        }

        return self::money($query->sum('due_total'));
    }

    /**
     * The customer's open POS orders, oldest first, locked FOR UPDATE in ascending id when asked.
     *
     * @return Collection<int, Order>
     */
    public function openOrders(int $customerId, bool $lock = false): Collection
    {
        $query = self::open($customerId)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * Open due for many customers at once (search results): customer id → open due; absent = 0.
     *
     * @param  list<int>  $customerIds
     * @return array<int, Money>
     */
    public function openDueFor(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }
        $out = [];
        $rows = Order::query()->toBase()
            ->whereIn('customer_id', $customerIds)
            ->where('source', OrderSource::Pos->value)
            ->where('status', OrderStatus::Delivered->value)
            ->where('due_total', '>', 0)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(due_total) as total')
            ->get();
        foreach ($rows as $row) {
            $out[(int) $row->customer_id] = self::money($row->total);
        }

        return $out;
    }

    /** limit − open due, never below zero. */
    public function available(Money $openDue): Money
    {
        $limit = $this->limit();

        return $limit->greaterThan($openDue) ? $limit->minus($openDue) : Money::zero();
    }

    /**
     * The due guards for a plan that puts something on due. `$customer` is who the phone
     * belongs to (null for a new number or no phone); `$openDue` their open due (zero when new).
     *
     * @return list<array{field: string, message: string}>
     */
    public function guard(TenderPlan $plan, bool $allowed, bool $hasPhone, ?Customer $customer, ?string $saleName, Money $openDue): array
    {
        if (! $plan->hasDue()) {
            return [];
        }
        $field = 'tenders.'.($plan->dueIndex ?? 0).'.method';

        if (! $allowed) {
            return [['field' => $field, 'message' => (string) __('pos.due.not_allowed')]];
        }
        if (! $hasPhone || ($customer !== null && PosCustomers::isWalkIn($customer))) {
            return [['field' => $field, 'message' => (string) __('pos.due.needs_customer')]];
        }
        $named = ($saleName !== null && trim($saleName) !== '')
            || ($customer !== null && ! PosCustomers::isPlaceholderName($customer->name));
        if (! $named) {
            return [['field' => $field, 'message' => (string) __('pos.due.needs_customer')]];
        }
        if ($openDue->plus($plan->dueAmount())->greaterThan($this->limit())) {
            return [['field' => $field, 'message' => (string) __('pos.due.over_limit', ['available' => $this->available($openDue)->toDecimal()])]];
        }

        return [];
    }

    /** @return Builder<Order> */
    private static function open(int $customerId): Builder
    {
        return Order::query()
            ->where('customer_id', $customerId)
            ->where('source', OrderSource::Pos->value)
            ->where('status', OrderStatus::Delivered->value)
            ->where('due_total', '>', 0);
    }

    private static function money(mixed $value): Money
    {
        return match (true) {
            $value instanceof Money => $value,
            is_float($value) => Money::of(number_format($value, 2, '.', '')),
            is_int($value), is_string($value) => Money::of((string) $value),
            default => Money::zero(),
        };
    }
}
