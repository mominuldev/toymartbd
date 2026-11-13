<?php

declare(strict_types=1);

namespace App\Domain\Notification\Queries;

use App\Domain\Notification\Enums\CartRecoverySkip;
use App\Domain\Notification\Enums\CartRecoveryStatus;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Models\CartRecovery;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Support\DhakaDate;
use App\Support\Money;

/**
 * The abandoned-cart report (P7) for a range of Dhaka dates (rule 11), bucketed by the
 * day the cart was abandoned:
 *
 *  - `abandoned`: carts detected (with a phone or a customer); `messaged`, `skipped` by reason;
 *  - `sent`: reminders the provider accepted; `cost`: what those SMS cost (notification_logs);
 *  - `restored`: restore links opened; `recovered`: reminded carts that became an order;
 *  - `revenue_recovered`: grand totals of those orders that are **delivered or completed** —
 *    revenue is recognised on delivery (rule 10), so a placed-but-undelivered order adds nothing.
 */
final class CartRecoveryReport
{
    /** @return array{from: string, to: string, abandoned: int, messaged: int, skipped: array<string, int>, sent: int, cost: string, restored: int, recovered: int, recovered_delivered: int, revenue_recovered: string, abandoned_value: string} */
    public function summary(string $from, string $to): array
    {
        [$start] = DhakaDate::utcBounds($from);
        [, $end] = DhakaDate::utcBounds($to);
        $rows = CartRecovery::query()->where('abandoned_at', '>=', $start)->where('abandoned_at', '<', $end)->get();

        $skipped = [];
        foreach (CartRecoverySkip::cases() as $reason) {
            $skipped[$reason->value] = 0;
        }
        $value = Money::zero();
        foreach ($rows as $row) {
            $value = $value->plus($row->cart_value);
            if ($row->status === CartRecoveryStatus::Skipped && $row->skip_reason !== null) {
                $skipped[$row->skip_reason->value]++;
            }
        }

        $ids = $rows->pluck('id')->all();
        $logs = $ids === [] ? collect() : \App\Domain\Notification\Models\NotificationLog::query()
            ->where('notifiable_type', CartRecovery::NOTIFIABLE)->whereIn('notifiable_id', $ids)->get(['status', 'cost']);
        $cost = Money::zero();
        foreach ($logs as $log) {
            $cost = $cost->plus($log->cost);
        }

        $orderIds = $rows->whereNotNull('recovered_order_id')->pluck('recovered_order_id')->all();
        $delivered = $orderIds === [] ? collect() : \App\Domain\Ordering\Models\Order::query()
            ->whereIn('id', $orderIds)
            ->whereIn('status', [OrderStatus::Delivered->value, OrderStatus::Completed->value])
            ->get(['id', 'grand_total']);
        $revenue = Money::zero();
        foreach ($delivered as $order) {
            $revenue = $revenue->plus($order->grand_total);
        }

        return [
            'from' => $from,
            'to' => $to,
            'abandoned' => $rows->count(),
            'messaged' => $rows->where('status', CartRecoveryStatus::Messaged)->count(),
            'skipped' => $skipped,
            'sent' => $logs->where('status', NotificationStatus::Sent)->count(),
            'cost' => $cost->toDecimal(),
            'restored' => $rows->whereNotNull('restored_at')->count(),
            'recovered' => count($orderIds),
            'recovered_delivered' => $delivered->count(),
            'revenue_recovered' => $revenue->toDecimal(),
            'abandoned_value' => $value->toDecimal(),
        ];
    }
}
