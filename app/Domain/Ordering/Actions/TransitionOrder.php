<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\InvalidOrderTransition;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Services\OrderTransitions;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A workflow-only status change — confirm, hold, release from hold, start processing,
 * mark packed, complete. These moves carry no stock or money side effect.
 *
 * The others have one and so have their own Action, which calls OrderTransitions inside
 * its own transaction: cancel → {@see CancelOrder} (releases the reservation); shipped →
 * {@see MarkOrderShipped} (consumes stock, §4.2; Phase 6 courier booking later); delivered →
 * {@see MarkOrderDelivered} (COD collected; Finance recognises revenue, §4.3); returned →
 * the returns flow (§4.4).
 */
final readonly class TransitionOrder
{
    public const array WORKFLOW_TARGETS = [
        OrderStatus::Pending,
        OrderStatus::OnHold,
        OrderStatus::Confirmed,
        OrderStatus::Processing,
        OrderStatus::ReadyToShip,
        OrderStatus::Completed,
    ];

    public function __construct(private OrderTransitions $transitions) {}

    /** @throws InvalidOrderTransition */
    public function handle(Order $order, OrderStatus $to, ?int $userId = null, ?string $note = null): Order
    {
        if (! in_array($to, self::WORKFLOW_TARGETS, true)) {
            throw new InvalidArgumentException("Moving an order to {$to->value} has side effects; use its dedicated Action.");
        }

        return DB::transaction(fn (): Order => $this->transitions->transition($order, $to, $userId, description: $note));
    }
}
