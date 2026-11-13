<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Payments;

use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\DTOs\PaymentContext;

/**
 * Builds the PaymentContext for an order's hosted-checkout session. The browser returns to
 * the storefront's route handler (`/api/payments/{method}/{number}/{outcome}`), which
 * forwards the signed POST to the API — the order number in that URL only routes the
 * customer back; status is always re-read from the API (never trusted from the query).
 * The IPN goes straight to the API's webhook listener.
 */
final class OnlinePaymentContext
{
    public static function for(Order $order, string $locale): PaymentContext
    {
        $order->loadMissing('customer');
        $base = rtrim((string) (config('payments.sslcommerz.return_base_url') ?: config('app.url')), '/');
        $method = (string) $order->payment_method;
        $locale = in_array($locale, ['en', 'bn'], true) ? $locale : 'bn';
        $url = static fn (string $outcome): string => "{$base}/api/payments/{$method}/{$order->number}/{$outcome}?locale={$locale}";

        return new PaymentContext(
            orderNumber: $order->number,
            customerName: $order->shipping_name,
            customerPhone: $order->shipping_phone,
            successUrl: $url('success'),
            failureUrl: $url('fail'),
            locale: $locale,
            cancelUrl: $url('cancel'),
            notifyUrl: route('api.v1.webhooks.payments.sslcommerz.ipn'),
            customerEmail: $order->customer->email,
            city: $order->shipping_district,
            postcode: $order->shipping_postcode,
        );
    }
}
