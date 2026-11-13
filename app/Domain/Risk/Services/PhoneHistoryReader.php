<?php

declare(strict_types=1);

namespace App\Domain\Risk\Services;

use App\Domain\Customer\Models\Customer;
use App\Domain\Risk\DTOs\PhoneHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads one phone's order history straight from `orders` (and `shipments` once the courier
 * module creates it). Risk never depends on Ordering's classes (deptrac): a read of the
 * table is the seam, the same way Ordering asks Risk for an assessment and never the reverse.
 * A phone matches an order shipped to it or owned by a customer with it (rule 33b).
 */
final class PhoneHistoryReader
{
    /** Cancellation reasons the customer caused (CancellationReason values). */
    public const array CUSTOMER_CANCEL_REASONS = ['customer_request', 'customer_unreachable', 'fake_order', 'other'];

    public function __construct(private RiskSettings $settings) {}

    public function forPhone(string $e164, ?CarbonImmutable $now = null): PhoneHistory
    {
        $now ??= CarbonImmutable::now();
        $customerId = Customer::query()->where('phone', $e164)->value('id');
        $customerId = is_numeric($customerId) ? (int) $customerId : null;

        $orders = fn (): Builder => DB::table('orders')->where(function (Builder $q) use ($e164, $customerId): void {
            $q->where('shipping_phone', $e164);
            if ($customerId !== null) {
                $q->orWhere('customer_id', $customerId);
            }
        });

        $row = $orders()->selectRaw(
            'COUNT(*) AS total,'
            .' SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) AS delivered,'
            ." SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,"
            ." SUM(CASE WHEN status = 'cancelled' AND (cancel_reason IS NULL OR cancel_reason IN ('".implode("','", self::CUSTOMER_CANCEL_REASONS)."')) THEN 1 ELSE 0 END) AS customer_cancelled,"
            ." SUM(CASE WHEN status IN ('returned', 'partially_returned') AND delivered_at IS NOT NULL THEN 1 ELSE 0 END) AS returned,"
            ." SUM(CASE WHEN status = 'returned' AND delivered_at IS NULL THEN 1 ELSE 0 END) AS refused,"
            .' MAX(placed_at) AS last_order_at',
        )->first();

        $average = $orders()->whereNotNull('delivered_at')->avg('grand_total');
        $recent = $orders()->where('placed_at', '>=', $now->subMinutes($this->settings->rapidRepeatMinutes()))->count();

        // Failed delivery ATTEMPTS on our parcels: every attempt but the successful one on a
        // delivered parcel, every attempt on one that came back. (`shipments.status = failed`
        // is a refused BOOKING, not a delivery, so it is not counted.)
        $failed = 0;
        if (Schema::hasTable('shipments')) {
            $value = DB::table('shipments')->whereIn('order_id', $orders()->select('id'))
                ->selectRaw("SUM(CASE WHEN status IN ('delivered', 'partially_delivered') THEN CASE WHEN delivery_attempts > 1 THEN delivery_attempts - 1 ELSE 0 END ELSE delivery_attempts END) AS failed")
                ->value('failed');
            $failed = is_numeric($value) ? (int) $value : 0;
        }

        $last = is_object($row) && is_string($row->last_order_at ?? null) ? CarbonImmutable::parse($row->last_order_at) : null;

        return new PhoneHistory(
            phone: $e164,
            customerId: $customerId,
            totalOrders: self::count($row, 'total'),
            deliveredCount: self::count($row, 'delivered'),
            cancelledCount: self::count($row, 'cancelled'),
            customerCancelCount: self::count($row, 'customer_cancelled'),
            returnedCount: self::count($row, 'returned'),
            refusedCount: self::count($row, 'refused'),
            failedDeliveryCount: $failed,
            averageDeliveredValue: is_numeric($average) ? number_format((float) $average, 2, '.', '') : null,
            recentOrders: $recent,
            lastOrderAt: $last,
        );
    }

    private static function count(mixed $row, string $column): int
    {
        $value = is_object($row) ? ($row->{$column} ?? 0) : 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
