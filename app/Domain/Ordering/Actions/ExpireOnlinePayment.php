<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Ordering\Enums\CancellationReason;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Actions\ReconcileOnlinePayment;
use App\Domain\Payment\DTOs\GatewayResult;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Services\PaymentTransitions;
use Illuminate\Support\Facades\DB;

/**
 * An online order whose payment window lapsed unpaid (P4-PAY-04): its stock goes back to
 * available and the order is cancelled — through the existing paths only:
 *
 *   1. no transaction: re-query every open attempt that reached the gateway (the customer
 *      may have paid and the IPN been lost) — rule 4. Paid → nothing expires. A refused
 *      answer flags the payment, and a flagged order is left for a human.
 *   2. one transaction: lock the order, re-check, move each open attempt to `expired`
 *      (PaymentTransitions), then CancelOrder — which releases the reservation through
 *      Inventory's ReleaseStock (FOR UPDATE, ascending variant id) and cancels the order
 *      with reason `payment_failed`.
 *
 * The window is per attempt (`payments.expires_at`, config payments.online.expiry_minutes —
 * a PLACEHOLDER); a retry opens a new attempt with a fresh window. Returns `paid`,
 * `expired` or `skipped`.
 */
final readonly class ExpireOnlinePayment
{
    public const string EXPIRED = 'expired';

    public const string PAID = 'paid';

    public const string SKIPPED = 'skipped';

    public function __construct(
        private ReconcileOnlinePayment $reconcile,
        private PaymentTransitions $payments,
        private CancelOrder $cancel,
    ) {}

    public function handle(Order $order): string
    {
        if (! self::eligible($order)) {
            return self::SKIPPED;
        }

        // 1. Ask the provider before giving up on any attempt it has seen.
        $open = Payment::query()->where('order_id', $order->id)
            ->whereIn('status', [PaymentStatus::Initiated->value, PaymentStatus::Authorised->value])
            ->whereNotNull('transaction_id')->orderBy('id')->get();
        foreach ($open as $payment) {
            $result = $this->reconcile->handle($payment);
            if ($result['payment']->status === PaymentStatus::Paid) {
                return self::PAID;
            }
            if ($result['outcome'] === ReconcileOnlinePayment::FLAGGED) {
                return self::SKIPPED;
            }
        }

        // 2. Expire and cancel, atomically.
        return DB::transaction(function () use ($order): string {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (! self::eligible($locked)) {
                return self::SKIPPED;
            }

            $open = Payment::query()->where('order_id', $locked->id)
                ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Initiated->value, PaymentStatus::Authorised->value])
                ->orderBy('id')->get();
            foreach ($open as $payment) {
                $this->payments->apply($payment, new GatewayResult(PaymentStatus::Expired, failureReason: 'payment_window_elapsed'));
            }

            $this->cancel->handle($locked, CancellationReason::PaymentFailed);

            return self::EXPIRED;
        });
    }

    /**
     * Unpaid online order, still holding its reservation, not under review, whose latest
     * attempt's window has passed.
     */
    public static function eligible(Order $order): bool
    {
        $method = PaymentMethod::tryFrom((string) $order->payment_method);
        if ($method === null || ! $method->isOnline() || ! in_array($order->status, [OrderStatus::Pending, OrderStatus::OnHold], true)) {
            return false;
        }

        $payments = Payment::query()->where('order_id', $order->id)->orderBy('id')->get();
        if ($payments->contains(fn (Payment $p): bool => $p->status === PaymentStatus::Paid || $p->review_reason !== null)) {
            return false;
        }
        /** @var Payment|null $latest */
        $latest = $payments->last();

        return $latest !== null && $latest->expires_at !== null && ! $latest->expires_at->isFuture();
    }
}
