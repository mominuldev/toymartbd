<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Domain\Payment\DTOs\GatewayResult;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Events\PaymentFlaggedForReview;
use App\Domain\Payment\Events\PaymentStatusChanged;
use App\Domain\Payment\Exceptions\InvalidPaymentTransition;
use App\Domain\Payment\Exceptions\PaymentAmountMismatch;
use App\Domain\Payment\Exceptions\PaymentViolation;
use App\Domain\Payment\Models\Payment;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `payments` (P4-PAY-01). Owns no transaction (rule 3) and refuses to
 * run outside the caller's. Every driver's result — COD collection included — is applied
 * here, so COD and online payments share one transition table ({@see PaymentStatus}).
 */
final readonly class PaymentTransitions
{
    /**
     * Create the order's payment row, `pending` for every method (§4.1). An online attempt
     * carries `expires_at` — when it stops holding the order's stock (P4-PAY-04).
     */
    public function open(int $orderId, PaymentMethod $method, Money $amount, ?CarbonInterface $expiresAt = null): Payment
    {
        self::guard();

        return Payment::query()->create([
            'order_id' => $orderId,
            'method' => $method,
            'gateway' => $method->value,
            'amount' => $amount,
            'status' => PaymentStatus::Pending,
            'expires_at' => $expiresAt ?? ($method->isOnline() ? now()->addMinutes(self::expiryMinutes()) : null),
        ]);
    }

    /** PLACEHOLDER window (config payments.online.expiry_minutes). */
    public static function expiryMinutes(): int
    {
        return max(1, (int) config('payments.online.expiry_minutes', 30));
    }

    /**
     * The provider reported something we refuse to apply — an amount or currency that does
     * not match, or `paid` for an attempt that is already closed. Nothing about the status
     * or money changes (rule 19); the payment is marked for a human and
     * PaymentFlaggedForReview tells Ordering to hold the order. Idempotent per reason.
     */
    public function flagForReview(Payment $payment, string $reason): Payment
    {
        self::guard();

        /** @var Payment $locked */
        $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
        if ($locked->review_reason === $reason) {
            return $locked;
        }
        $locked->review_reason = mb_substr($reason, 0, 64);
        $locked->review_flagged_at = now();
        $locked->save();

        PaymentFlaggedForReview::dispatch($locked->id, $locked->order_id, $locked->method->value, $locked->review_reason);

        return $locked;
    }

    /**
     * Apply a driver's answer. Locks the payment row; a result equal to the current status
     * is a no-op (a replayed webhook or a second poll changes nothing). `paid` requires the
     * reported amount to equal the payment's (rule 19) — otherwise nothing is written.
     *
     * @throws InvalidPaymentTransition
     * @throws PaymentAmountMismatch
     */
    public function apply(Payment $payment, GatewayResult $result, ?int $userId = null): Payment
    {
        self::guard();

        /** @var Payment $locked */
        $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
        $from = $locked->status;
        $to = $result->status;

        if ($from === $to) {
            return $locked;
        }
        if (! $from->canTransitionTo($to)) {
            throw InvalidPaymentTransition::between($from, $to);
        }

        if ($to === PaymentStatus::Paid) {
            $reported = $result->amount ?? throw PaymentAmountMismatch::for($locked->id, (string) $locked->amount, 'nothing');
            if (! $reported->equals($locked->amount)) {
                throw PaymentAmountMismatch::for($locked->id, (string) $locked->amount, (string) $reported);
            }
            $fee = $result->fee ?? Money::zero();
            $locked->paid_at = now();
            $locked->verified_at = now();
            $locked->fee_amount = $fee;
            $locked->net_amount = $locked->amount->minus($fee);
            $locked->collected_by = $locked->method === PaymentMethod::Cod ? $userId : null;
        }

        $locked->status = $to;
        $locked->transaction_id = $result->transactionId ?? $locked->transaction_id;
        $locked->gateway_reference = $result->gatewayReference ?? $locked->gateway_reference;
        $locked->failure_reason = $result->failureReason ?? $locked->failure_reason;
        if ($result->payload !== []) {
            $locked->gateway_payload = $result->payload;
        }
        $locked->save();

        PaymentStatusChanged::dispatch(
            $locked->id, $locked->order_id, $locked->method->value, $from->value, $to->value, (string) $locked->amount,
        );

        return $locked;
    }

    /**
     * Cancel every open payment of an order (order cancelled before money moved).
     *
     * @return list<Payment> the payments cancelled
     */
    public function cancelOpen(int $orderId, string $reason): array
    {
        self::guard();

        $cancelled = [];
        $open = Payment::query()->where('order_id', $orderId)
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Initiated->value, PaymentStatus::Authorised->value])
            ->orderBy('id')->get();

        foreach ($open as $payment) {
            $cancelled[] = $this->apply($payment, new GatewayResult(PaymentStatus::Cancelled, failureReason: $reason));
        }

        return $cancelled;
    }

    /**
     * Re-amount an order's still-`pending` payments after the order total changed before
     * any money moved (an order edit, P4-ORD-07). Only `pending` rows change: a payment
     * that was initiated, authorised or paid is never re-amounted — the caller must refuse
     * the edit instead (see {@see hasMoneyInFlight()}).
     *
     * @return int how many payments were re-amounted
     */
    public function reamountPending(int $orderId, Money $amount): int
    {
        self::guard();

        $pending = Payment::query()->where('order_id', $orderId)
            ->where('status', PaymentStatus::Pending->value)
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($pending as $payment) {
            $payment->amount = $amount;
            $payment->save();
        }

        return $pending->count();
    }

    /** True when some payment of the order is past `pending` and not closed unpaid. */
    public static function hasMoneyInFlight(int $orderId): bool
    {
        return Payment::query()->where('order_id', $orderId)
            ->whereIn('status', [PaymentStatus::Initiated->value, PaymentStatus::Authorised->value, PaymentStatus::Paid->value])
            ->exists();
    }

    private static function guard(): void
    {
        if (DB::transactionLevel() === 0) {
            throw PaymentViolation::outsideTransaction();
        }
    }
}
