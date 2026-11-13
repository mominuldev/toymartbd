<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Ordering\Enums\CancellationReason;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\VerificationStatus;
use App\Domain\Ordering\Exceptions\VerificationNotPending;
use App\Domain\Ordering\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Verification queue → "Cancel": a PERSON decided, after looking, that the held order is not
 * genuine. The only way the queue cancels anything (rules 23, 24b — scoring never does). Marks
 * verification `failed` (who/when) and cancels through {@see CancelOrder} (releases stock,
 * cancels open payments) in one transaction. The reason is required.
 */
final readonly class RejectHeldOrder
{
    public function __construct(private CancelOrder $cancel) {}

    /** @throws VerificationNotPending */
    public function handle(Order $order, CancellationReason $reason, ?string $note, ?int $userId): Order
    {
        return DB::transaction(function () use ($order, $reason, $note, $userId): Order {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->verification_status !== VerificationStatus::Pending || $locked->status !== OrderStatus::OnHold) {
                throw VerificationNotPending::for($locked->number, $locked->verification_status->value);
            }

            Order::query()->whereKey($locked->id)->update([
                'verification_status' => VerificationStatus::Failed->value,
                'verified_by' => $userId,
                'verified_at' => now(),
            ]);

            return $this->cancel->handle($locked, $reason, $note, $userId);
        });
    }
}
