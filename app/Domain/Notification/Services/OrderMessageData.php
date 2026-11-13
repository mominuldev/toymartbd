<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Ordering\Models\Order;
use App\Support\Locale\Formatter;
use App\Support\Money;

/**
 * The template variables for one order, in one locale. Everything here is formatting of
 * figures Ordering already computed (grand_total, due_total, paid_total) — nothing is
 * priced (rule 1). Phone numbers and addresses are never variables (rule 20); the
 * recipient is resolved separately, at send time.
 *
 * `courier`, `consignment`, `eta` and `date_range` fall back to PLACEHOLDER copy until
 * Fulfilment ships shipments and per-order ETAs (Phase 6).
 */
final class OrderMessageData
{
    /** @return array<string, string> */
    public function variables(Order $order, string $locale): array
    {
        $order->loadMissing('items');

        return [
            'name' => $order->shipping_name,
            'order_number' => $order->number,
            'item_count' => (string) $order->items->sum('quantity'),
            'total' => self::amount($order->grand_total),
            'cod_amount' => self::amount($order->due_total),
            'track_url' => config('notifications.short_link_base', 'tmbd.co').'/'.$order->number,
            'hotline' => (string) config('notifications.hotline'),
            // PLACEHOLDER (P6): neither courier's status API returns the rider's number, so
            // `order.out_for_delivery` names our hotline rather than send a literal {rider_phone}.
            'rider_phone' => (string) config('notifications.hotline'),
            'store_url' => (string) config('notifications.store_url'),
            'date_range' => (string) trans('notifications.delivery_window', [], $locale),
            'eta' => (string) trans('notifications.shipping_eta', [], $locale),
            'courier' => (string) trans('notifications.courier_fallback', [], $locale),
            'consignment' => (string) trans('notifications.consignment_pending', [], $locale),
            'date' => $order->delivered_at !== null ? Formatter::date($order->delivered_at, $locale) : '',
            'refund_sentence' => $order->paid_total->greaterThan(Money::zero())
                ? (string) trans('notifications.refund_sentence', ['amount' => self::amount($order->paid_total)], $locale)
                : '',
        ];
    }

    /** `2450` / `2450.50` — the figure after "BDT " in a template (GSM-7 safe). */
    public static function amount(Money $money): string
    {
        return substr(Formatter::smsMoney($money), 4);
    }
}
