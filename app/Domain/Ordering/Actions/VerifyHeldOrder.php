<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\VerificationStatus;
use App\Domain\Ordering\Exceptions\VerificationNotPending;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Services\OrderTransitions;
use Illuminate\Support\Facades\DB;

/**
 * Verification queue → "Verify & confirm": a person spoke to the customer and the order is
 * genuine. Records who and when (`verification_status = verified`, `verified_by`,
 * `verified_at`) and CONFIRMS the order (on_hold → confirmed), in one transaction — owner
 * decision 2026-11-13: the call that verifies an order is the confirmation call, so it does
 * not go back to `pending` to be confirmed a second time.
 *
 * The move goes through the same {@see OrderTransitions::transition()} as every other
 * confirm ({@see TransitionOrder}, {@see BulkConfirmOrders}): `confirmed_at` is stamped, the
 * timeline row is written and `OrderConfirmed` is dispatched after commit, so the customer's
 * "order confirmed" message and every other confirm listener fire exactly as for a normal
 * confirm. Only a held order waiting for verification qualifies; anything else is a 409.
 */
final readonly class VerifyHeldOrder
{
    public function __construct(private OrderTransitions $transitions) {}

    /** @throws VerificationNotPending */
    public function handle(Order $order, ?int $userId, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $userId, $note): Order {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->verification_status !== VerificationStatus::Pending || $locked->status !== OrderStatus::OnHold) {
                throw VerificationNotPending::for($locked->number, $locked->verification_status->value);
            }

            return $this->transitions->transition(
                $locked,
                OrderStatus::Confirmed,
                $userId,
                attributes: [
                    'verification_status' => VerificationStatus::Verified,
                    'verified_by' => $userId,
                    'verified_at' => now(),
                ],
                meta: ['verification' => 'verified'],
                description: $note ?? 'Verified by phone and confirmed.',
            );
        });
    }
}
