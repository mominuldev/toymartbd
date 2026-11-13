<?php

declare(strict_types=1);

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\DTOs\GatewayResult;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Exceptions\InvalidPaymentTransition;
use App\Domain\Payment\Exceptions\PaymentAmountMismatch;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Services\PaymentGatewayRegistry;
use App\Domain\Payment\Services\PaymentTransitions;
use Illuminate\Support\Facades\DB;

/**
 * Apply the provider's AUTHORITATIVE answer to an online payment (rule 19) — the one path
 * every online status change takes: a verified IPN, the browser return, the expiry
 * sweeper, an admin re-check.
 *
 *   1. no transaction: the provider call (`verify()` by tran_id), unless the caller already
 *      holds the re-queried answer (the validation API for a signed `val_id`) — rule 4;
 *   2. a short transaction: PaymentTransitions::apply;
 *   3. a refused `paid` (amount or currency mismatch, or the attempt already closed) is
 *      NEVER written as paid: the payment is flagged for review instead, and Ordering
 *      holds the order.
 *
 * Returns what happened, for the webhook receipt and the caller.
 */
final readonly class ReconcileOnlinePayment
{
    public const string APPLIED = 'applied';

    public const string UNCHANGED = 'unchanged';

    public const string FLAGGED = 'flagged';

    public const string IGNORED = 'ignored';

    public function __construct(
        private PaymentGatewayRegistry $gateways,
        private PaymentTransitions $transitions,
    ) {}

    /** @return array{outcome: string, payment: Payment, reason: string|null} */
    public function handle(Payment $payment, ?GatewayResult $answer = null): array
    {
        $answer ??= $this->gateways->for($payment->method)->verify($payment);
        $before = $payment->status;

        try {
            $applied = DB::transaction(fn (): Payment => $this->transitions->apply($payment, $answer));
        } catch (PaymentAmountMismatch) {
            return $this->flag($payment, $answer->failureReason === 'currency_mismatch' ? 'currency_mismatch' : 'amount_mismatch');
        } catch (InvalidPaymentTransition) {
            if ($answer->status === PaymentStatus::Paid) {
                return $this->flag($payment, 'paid_after_close');
            }

            return ['outcome' => self::IGNORED, 'payment' => $payment->refresh(), 'reason' => 'closed'];
        }

        return [
            'outcome' => $applied->status === $before ? self::UNCHANGED : self::APPLIED,
            'payment' => $applied,
            'reason' => $answer->failureReason,
        ];
    }

    /** @return array{outcome: string, payment: Payment, reason: string} */
    private function flag(Payment $payment, string $reason): array
    {
        $flagged = DB::transaction(fn (): Payment => $this->transitions->flagForReview($payment, $reason));

        return ['outcome' => self::FLAGGED, 'payment' => $flagged, 'reason' => $reason];
    }
}
