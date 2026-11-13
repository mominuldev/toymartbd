<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Queries;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Coupon and promotion usage reports for the admin (P7). Reads `coupon_usages` /
 * `promotion_usages` joined to the orders they were used on. A cancelled order's usage
 * is reversed (ReverseOrderMarketingUsage), so it is not counted here.
 *
 * `delivered_revenue` counts only delivered or completed orders (revenue is recognised
 * on delivery, rule 10); `gross_sales` is every order's grand total, as placed.
 */
final class MarketingUsageReport
{
    public const int RECENT = 50;

    /** @return array{summary: array{orders: int, discount_total: string, gross_sales: string, delivered_orders: int, delivered_revenue: string}, recent: list<array{order_id: int, order_number: string, status: string, phone: string, discount: string, grand_total: string, used_at: string}>} */
    public function forCoupon(int $couponId): array
    {
        return $this->report(DB::table('coupon_usages as u')->where('u.coupon_id', $couponId));
    }

    /** @return array{summary: array{orders: int, discount_total: string, gross_sales: string, delivered_orders: int, delivered_revenue: string}, recent: list<array{order_id: int, order_number: string, status: string, phone: string, discount: string, grand_total: string, used_at: string}>} */
    public function forPromotion(int $promotionId): array
    {
        return $this->report(DB::table('promotion_usages as u')->where('u.promotion_id', $promotionId));
    }

    /**
     * Coupons of one bulk batch, summed.
     *
     * @return array{orders: int, discount_total: string, gross_sales: string, delivered_orders: int, delivered_revenue: string}
     */
    public function forBatch(string $batch): array
    {
        return $this->summary(DB::table('coupon_usages as u')->join('coupons', 'coupons.id', '=', 'u.coupon_id')->where('coupons.batch', $batch));
    }

    /** @return array{summary: array{orders: int, discount_total: string, gross_sales: string, delivered_orders: int, delivered_revenue: string}, recent: list<array{order_id: int, order_number: string, status: string, phone: string, discount: string, grand_total: string, used_at: string}>} */
    private function report(Builder $usages): array
    {
        $recent = (clone $usages)
            ->join('orders as o', 'o.id', '=', 'u.order_id')
            ->orderByDesc('u.used_at')->orderByDesc('u.id')
            ->limit(self::RECENT)
            ->get(['o.id', 'o.number', 'o.status', 'u.phone', 'u.discount_amount', 'o.grand_total', 'u.used_at']);

        return [
            'summary' => $this->summary($usages),
            'recent' => array_values($recent->map(static fn (object $r): array => [
                'order_id' => (int) $r->id,
                'order_number' => (string) $r->number,
                'status' => (string) $r->status,
                // Rule 20: never a full phone in an admin report.
                'phone' => PhoneNumber::tryParse((string) $r->phone)?->masked() ?? '•••',
                'discount' => Money::of((string) $r->discount_amount)->toDecimal(),
                'grand_total' => Money::of((string) $r->grand_total)->toDecimal(),
                'used_at' => (string) \Illuminate\Support\Carbon::parse((string) $r->used_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            ])->all()),
        ];
    }

    /** @return array{orders: int, discount_total: string, gross_sales: string, delivered_orders: int, delivered_revenue: string} */
    private function summary(Builder $usages): array
    {
        $delivered = [OrderStatus::Delivered->value, OrderStatus::Completed->value];
        $row = (clone $usages)
            ->join('orders as o', 'o.id', '=', 'u.order_id')
            ->selectRaw('COUNT(DISTINCT u.order_id) as orders, COALESCE(SUM(u.discount_amount), 0) as discount_total, COALESCE(SUM(o.grand_total), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(CASE WHEN o.status IN (?, ?) THEN 1 ELSE 0 END), 0) as delivered_orders', $delivered)
            ->selectRaw('COALESCE(SUM(CASE WHEN o.status IN (?, ?) THEN o.grand_total ELSE 0 END), 0) as delivered_revenue', $delivered)
            ->first();

        return [
            'orders' => (int) ($row->orders ?? 0),
            'discount_total' => Money::of(self::dec($row->discount_total ?? 0))->toDecimal(),
            'gross_sales' => Money::of(self::dec($row->gross_sales ?? 0))->toDecimal(),
            'delivered_orders' => (int) ($row->delivered_orders ?? 0),
            'delivered_revenue' => Money::of(self::dec($row->delivered_revenue ?? 0))->toDecimal(),
        ];
    }

    /** A SQL SUM as a 2-dp decimal string — MySQL returns a DECIMAL string; SQLite may return a number. */
    private static function dec(mixed $v): string
    {
        $s = is_string($v) || is_int($v) ? (string) $v : sprintf('%.2F', is_float($v) ? $v : 0.0);

        return preg_match('/^-?\d+(\.\d+)?$/', $s) === 1 ? bcadd($s, '0', 2) : '0.00';
    }
}
