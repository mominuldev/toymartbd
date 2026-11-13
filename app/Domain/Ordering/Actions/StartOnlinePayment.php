<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\OnlinePaymentNotAllowed;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Payments\OnlinePaymentContext;
use App\Domain\Ordering\Payments\OnlinePaymentStart;
use App\Domain\Payment\Actions\InitiatePayment;
use App\Domain\Payment\Actions\ReconcileOnlinePayment;
use App\Domain\Payment\DTOs\GatewayResult;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Services\OnlineGateways;
use App\Domain\Payment\Services\PaymentTransitions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Start — or retry — the hosted-checkout payment of an online order (P4-PAY-02/04).
 *
 * The order's stock was reserved once, at PlaceOrder, and stays reserved until the order is
 * paid or its window lapses (ExpireOnlinePayment). A retry never reserves again: it only
 * opens a new payment attempt (terminal payments never reopen) with a fresh window.
 *
 *   - the latest attempt is `pending` (fresh from PlaceOrder) → initiate it;
 *   - the latest attempt is `initiated` with a live session → re-query it first (it may be
 *     paid); still open → hand back the SAME session URL (never two live sessions, so the
 *     customer cannot pay twice);
 *   - the latest attempt failed / was cancelled → open a new attempt and initiate it.
 *
 * Provider calls happen outside any transaction (rule 4): a short transaction opens the
 * attempt; InitiatePayment then calls the driver and applies its answer in its own.
 *
 * @throws OnlinePaymentNotAllowed
 */
final readonly class StartOnlinePayment
{
    public function __construct(
        private OnlineGateways $gateways,
        private PaymentTransitions $payments,
        private InitiatePayment $initiate,
        private ReconcileOnlinePayment $reconcile,
    ) {}

    public function handle(Order $order, string $locale): OnlinePaymentStart
    {
        $method = PaymentMethod::tryFrom((string) $order->payment_method);
        if ($method === null || ! $method->isOnline()) {
            throw new OnlinePaymentNotAllowed('not_online');
        }
        if (! $this->gateways->isEnabled($method)) {
            throw new OnlinePaymentNotAllowed('gateway_unavailable');
        }

        self::assertPayable($order->refresh(), Payment::query()->where('order_id', $order->id)->orderBy('id')->get());

        $latest = self::latest($order);
        if ($latest !== null && $latest->status === PaymentStatus::Initiated) {
            $latest = $this->reconcile->handle($latest)['payment'];
            if ($latest->status === PaymentStatus::Initiated) {
                $url = $latest->gateway_payload['GatewayPageURL'] ?? null;
                if (is_string($url) && $url !== '' && ($latest->expires_at === null || $latest->expires_at->isFuture())) {
                    return new OnlinePaymentStart($latest, $url, resumed: true);
                }
            }
        }

        /** @var Payment $attempt */
        $attempt = DB::transaction(function () use ($order, $method): Payment {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $payments = Payment::query()->where('order_id', $locked->id)->orderBy('id')->lockForUpdate()->get();

            self::assertPayable($locked, $payments);

            /** @var Payment|null $last */
            $last = $payments->last();
            if ($last !== null && $last->status === PaymentStatus::Pending) {
                return $last;
            }
            if ($last !== null && $last->status->isOpen()) {
                // Initiated without a usable session URL (or past its window): close it so
                // only one session is ever live, then open a fresh attempt below.
                $this->payments->apply($last, new GatewayResult(PaymentStatus::Cancelled, failureReason: 'superseded_by_retry'));
            }
            if ($payments->count() >= max(1, (int) config('payments.online.max_attempts', 5))) {
                throw new OnlinePaymentNotAllowed('too_many_attempts');
            }

            return $this->payments->open($locked->id, $method, $locked->grand_total);
        });

        $result = $this->initiate->handle($attempt, OnlinePaymentContext::for($order, $locale));

        return new OnlinePaymentStart($attempt->refresh(), $result->status === PaymentStatus::Initiated ? $result->redirectUrl : null);
    }

    /**
     * @param  Collection<int, Payment>  $payments
     *
     * @throws OnlinePaymentNotAllowed
     */
    private static function assertPayable(Order $order, Collection $payments): void
    {
        if ($payments->contains(fn (Payment $p): bool => $p->status === PaymentStatus::Paid)) {
            throw new OnlinePaymentNotAllowed('already_paid');
        }
        if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::OnHold], true)) {
            throw new OnlinePaymentNotAllowed('order_closed');
        }
        if ($payments->contains(fn (Payment $p): bool => $p->review_reason !== null)) {
            throw new OnlinePaymentNotAllowed('under_review');
        }
    }

    private static function latest(Order $order): ?Payment
    {
        return Payment::query()->where('order_id', $order->id)->orderByDesc('id')->first();
    }
}
