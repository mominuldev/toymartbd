<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries\Customers;

use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lifetime value per customer, from the ledger projection — never from placed order value
 * (rule 10). One definition, used by the list, the profile and the analytics page:
 *
 *   lifetime_value = Σ order_financials.revenue − Σ order_financials.refund_amount
 *
 * over the customer's orders. `order_financials` is rebuilt from `financial_transactions`
 * (Finance\Services\OrderFinancialsProjector), so revenue = recognised sale_revenue −
 * discount_given (line totals after discounts, delivery charges excluded) and only exists
 * once an order is delivered. A test holds Σ lifetime_value equal to the ledger.
 */
final readonly class CustomerLedgerValues
{
    /**
     * `customer_id`, `lifetime_value`, `delivered_revenue`, `refunds` per customer that has
     * at least one order with a financials row. Left-join it as `ltv`.
     */
    public static function subquery(): Builder
    {
        return DB::table('orders')
            ->join('order_financials', 'order_financials.order_id', '=', 'orders.id')
            ->groupBy('orders.customer_id')
            ->select('orders.customer_id')
            ->selectRaw('SUM(order_financials.revenue) - SUM(order_financials.refund_amount) as lifetime_value')
            ->selectRaw('SUM(order_financials.revenue) as delivered_revenue')
            ->selectRaw('SUM(order_financials.refund_amount) as refunds');
    }

    /** @return array{lifetime_value: Money, delivered_revenue: Money, refunds: Money} */
    public static function forCustomer(int $customerId): array
    {
        $row = self::subquery()->where('orders.customer_id', $customerId)->first();

        return [
            'lifetime_value' => self::money($row->lifetime_value ?? null),
            'delivered_revenue' => self::money($row->delivered_revenue ?? null),
            'refunds' => self::money($row->refunds ?? null),
        ];
    }

    /** A SUM from the database (string, int, float or null) as Money, to the poisha. */
    public static function money(mixed $value): Money
    {
        return match (true) {
            is_string($value) && $value !== '' => Money::of($value),
            is_int($value) => Money::of((string) $value),
            is_float($value) => Money::of(number_format($value, 2, '.', '')),
            default => Money::zero(),
        };
    }
}
