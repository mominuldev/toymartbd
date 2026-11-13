<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions\Returns;

use App\Domain\Ordering\Actions\SyncOrderPaymentStatus;
use App\Domain\Ordering\Enums\OrderEventType;
use App\Domain\Ordering\Enums\ReturnStatus;
use App\Domain\Ordering\Events\RefundPaid;
use App\Domain\Ordering\Exceptions\ReturnNotAllowed;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderEvent;
use App\Domain\Ordering\Models\OrderReturn;
use App\Domain\Ordering\Services\ReturnQuantities;
use App\Domain\Payment\DTOs\RefundResult;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Gateways\CashOnDeliveryGateway;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\Refund;
use App\Domain\Payment\Services\PaymentGatewayRegistry;
use App\Support\DhakaDate;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pay an approved refund out (§4.7 "then: gateway refund call"). Idempotent: paying a
 * completed refund again returns it unchanged and fires nothing; the endpoint also
 * carries an Idempotency-Key (rule 17 spirit).
 *
 * Never more than was captured: Σ completed refunds + this one ≤ Σ paid payments.
 *
 *   COD (and cash) — there is no provider to call. Staff paid by hand (bKash / Nagad
 *     send-money, cash, bank) and enter the method and reference; the COD driver turns
 *     that into a `completed` result, all in one transaction.
 *   Online — three steps so no lock is held across network I/O (rule 4):
 *     1. transaction: claim the refund (approved/failed → processing);
 *     2. no transaction: the driver's refund() call;
 *     3. transaction: apply the answer (completed / failed / still processing).
 *
 * On completion: processed_at, the reference, the linked return closes, an order event,
 * the order's payment_status re-derived (partially_refunded / refunded), and RefundPaid
 * (after commit) for Finance's cash-out entry.
 */
final readonly class PayRefund
{
    public const array MANUAL_METHODS = [PaymentMethod::Bkash, PaymentMethod::Nagad, PaymentMethod::Cash, PaymentMethod::BankTransfer];

    public function __construct(
        private ReturnQuantities $quantities,
        private CashOnDeliveryGateway $cod,
        private PaymentGatewayRegistry $gateways,
        private SyncOrderPaymentStatus $sync,
    ) {}

    /**
     * @throws ReturnNotAllowed
     * @throws ValidationException a manual payout without its method or reference
     */
    public function handle(Refund $refund, ?PaymentMethod $via = null, ?string $reference = null, ?int $userId = null): Refund
    {
        $reference = $reference !== null && trim($reference) !== '' ? trim($reference) : null;

        /** @var array{0: Refund, 1: Payment|null} $claimed */
        $claimed = DB::transaction(function () use ($refund, $via, $reference, $userId): array {
            [$order, $locked] = $this->lock($refund);

            if ($locked->status === RefundStatus::Completed || $locked->status === RefundStatus::Processing) {
                return [$locked, null];
            }
            if (! in_array($locked->status, [RefundStatus::Approved, RefundStatus::Failed], true)) {
                throw ReturnNotAllowed::refundStatus($locked->id, $locked->status->value, RefundStatus::Completed->value);
            }

            $payment = $locked->payment_id !== null ? Payment::query()->find($locked->payment_id) : null;
            if ($payment === null) {
                throw ReturnNotAllowed::nothingCaptured($locked->id);
            }
            $this->assertWithinCaptured($order, $locked);

            if (self::isManual($payment)) {
                if ($via === null || ! in_array($via, self::MANUAL_METHODS, true)) {
                    throw ValidationException::withMessages(['method' => __('returns.payout_method_required')]);
                }
                if ($reference === null && $via !== PaymentMethod::Cash) {
                    throw ValidationException::withMessages(['reference' => __('returns.payout_reference_required')]);
                }

                return [$this->complete($order, $locked, $this->cod->manualPayout($payment, $locked->amount, $via, $reference), $via, $userId), null];
            }

            $locked->status = RefundStatus::Processing;
            $locked->save();

            return [$locked, $payment];
        });

        [$claimedRefund, $payment] = $claimed;
        if ($payment === null) {
            return $claimedRefund;
        }

        try {
            $result = $this->gateways->for($payment->method)->refund($payment, $claimedRefund->amount, $claimedRefund->reason);
        } catch (Throwable $e) {
            Log::warning('refund.gateway_error', ['refund_id' => $claimedRefund->id, 'exception' => $e::class]);
            $result = new RefundResult(RefundStatus::Failed, payload: ['error' => $e::class]);
        }

        return DB::transaction(function () use ($claimedRefund, $result, $userId): Refund {
            [$order, $locked] = $this->lock($claimedRefund);
            if ($locked->status !== RefundStatus::Processing) {
                return $locked;
            }

            return match ($result->status) {
                RefundStatus::Completed => $this->complete($order, $locked, $result, null, $userId),
                RefundStatus::Failed => tap($locked, static function (Refund $r) use ($result): void {
                    $r->status = RefundStatus::Failed;
                    $r->gateway_reference = $result->gatewayReference ?? $r->gateway_reference;
                    $r->save();
                }),
                default => $locked,
            };
        });
    }

    public static function isManual(Payment $payment): bool
    {
        return in_array($payment->method, [PaymentMethod::Cod, PaymentMethod::Cash], true);
    }

    /** @return array{0: Order, 1: Refund} order first, then refund — the order every return Action locks in */
    private function lock(Refund $refund): array
    {
        /** @var Order $order */
        $order = Order::query()->lockForUpdate()->findOrFail($refund->order_id);
        /** @var Refund $locked */
        $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);

        return [$order, $locked];
    }

    private function assertWithinCaptured(Order $order, Refund $refund): void
    {
        $paidOut = Refund::query()->where('order_id', $order->id)
            ->where('status', RefundStatus::Completed->value)
            ->whereKeyNot($refund->id)
            ->get(['amount'])
            ->reduce(static fn (Money $c, Refund $r): Money => $c->plus($r->amount), Money::zero());
        $left = $this->quantities->captured($order->id)->minus($paidOut);

        if ($refund->amount->greaterThan($left)) {
            throw ReturnNotAllowed::exceedsCaptured($refund->id, ($left->isNegative() ? Money::zero() : $left)->toDecimal());
        }
    }

    private function complete(Order $order, Refund $refund, RefundResult $result, ?PaymentMethod $via, ?int $userId): Refund
    {
        $refund->status = RefundStatus::Completed;
        $refund->method = $via ?? $refund->method;
        $refund->gateway_reference = $result->gatewayReference ?? $refund->gateway_reference;
        $refund->processed_at = now();
        $refund->save();

        OrderReturn::query()->where('refund_id', $refund->id)->where('status', ReturnStatus::Inspected->value)
            ->update(['status' => ReturnStatus::Closed->value, 'closed_at' => now()]);

        OrderEvent::query()->create([
            'order_id' => $order->id,
            'type' => OrderEventType::RefundPaid,
            'meta' => ['refund_id' => $refund->id, 'amount' => $refund->amount->toDecimal(), 'method' => $refund->method->value],
            'user_id' => $userId,
            'is_customer_visible' => false,
        ]);

        $this->sync->handle($order->id);

        RefundPaid::dispatch(
            $refund->id, $order->id, $refund->amount->toDecimal(), $refund->method->value, DhakaDate::today(), $userId,
        );

        return $refund;
    }
}
