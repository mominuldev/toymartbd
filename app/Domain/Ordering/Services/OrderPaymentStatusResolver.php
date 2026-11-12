<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Services;

use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Enums\RefundStatus;
use App\Support\Money;

/**
 * Derives `orders.payment_status` from the order's payments and refunds — one rule for
 * every method, COD included:
 *
 *   refunded > 0                      → refunded (≥ paid) | partially_refunded
 *   paid ≥ grand_total, paid > 0      → paid
 *   paid > 0                          → partially_paid
 *   an online payment initiated/authorised → pending (the customer is at the gateway)
 *   the latest payment failed/expired → failed
 *   otherwise                         → unpaid (COD before collection, or nothing open)
 */
final class OrderPaymentStatusResolver
{
    public function resolve(Order $order): DerivedPaymentState
    {
        $paid = Money::zero();
        $refunded = Money::zero();
        $atGateway = false;

        foreach ($order->payments as $payment) {
            if ($payment->status === PaymentStatus::Paid) {
                $paid = $paid->plus($payment->amount);
            }
            if ($payment->method !== PaymentMethod::Cod
                && in_array($payment->status, [PaymentStatus::Initiated, PaymentStatus::Authorised], true)) {
                $atGateway = true;
            }
        }
        foreach ($order->refunds as $refund) {
            if ($refund->status === RefundStatus::Completed) {
                $refunded = $refunded->plus($refund->amount);
            }
        }

        $latest = $order->payments->last();

        $status = match (true) {
            ! $refunded->isZero() => $refunded->compareTo($paid) >= 0 ? OrderPaymentStatus::Refunded : OrderPaymentStatus::PartiallyRefunded,
            ! $paid->isZero() && $paid->compareTo($order->grand_total) >= 0 => OrderPaymentStatus::Paid,
            ! $paid->isZero() => OrderPaymentStatus::PartiallyPaid,
            $atGateway => OrderPaymentStatus::Pending,
            $latest !== null && in_array($latest->status, [PaymentStatus::Failed, PaymentStatus::Expired], true) => OrderPaymentStatus::Failed,
            default => OrderPaymentStatus::Unpaid,
        };

        return new DerivedPaymentState($status, $paid, $refunded);
    }
}
