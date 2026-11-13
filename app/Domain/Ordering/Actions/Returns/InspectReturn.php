<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions\Returns;

use App\Domain\Inventory\Actions\RestockReturnedItems;
use App\Domain\Ordering\Enums\FulfillmentStatus;
use App\Domain\Ordering\Enums\OrderEventType;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\ReturnStatus;
use App\Domain\Ordering\Events\ReturnInspected;
use App\Domain\Ordering\Exceptions\ReturnNotAllowed;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderEvent;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\Models\OrderReturn;
use App\Domain\Ordering\Services\OrderTransitions;
use App\Domain\Ordering\Services\RefundCalculator;
use App\Domain\Ordering\Services\ReturnQuantities;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Enums\RefundType;
use App\Domain\Payment\Models\Refund;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Support\DhakaDate;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inspect a return (DATABASE-DESIGN §4.7, PRD I-11) — the step where goods, stock, the
 * order and the money owed all move, in one transaction:
 *
 *   lock the order (FOR UPDATE), then the return →
 *   record resellable / damaged per line (they must add up to what came back) →
 *   order_items.quantity_returned += n →
 *   Inventory RestockReturnedItems: customer_return_in for every unit, damage_out for the
 *     damaged ones, at the line's snapshot cost (levels locked in ascending variant id) →
 *   order status → partially_returned, or returned once every unit is back; fulfilment →
 *     returned with it →
 *   a refund in `requested` for the server-computed amount (RefundCalculator: the units'
 *     share of the goods paid, plus the delivery charge if claimed), capped at what is
 *     still refundable on captured payments, with refund_items and an audit_sensitive row.
 *     Nothing captured (or nothing left) → no refund, the return closes.
 *
 * ReturnInspected (after commit) lets Finance reverse COGS at the snapshot cost (rule 7)
 * and write the damaged units off.
 */
final readonly class InspectReturn
{
    public function __construct(
        private RestockReturnedItems $restock,
        private OrderTransitions $transitions,
        private RefundCalculator $calculator,
        private ReturnQuantities $quantities,
    ) {}

    /**
     * @param  array<int, array{resellable: int, damaged: int}>  $inspection  order item id → result
     *
     * @throws ValidationException
     * @throws ReturnNotAllowed
     */
    public function handle(OrderReturn $return, array $inspection, ?int $userId = null, ?string $note = null): OrderReturn
    {
        return DB::transaction(function () use ($return, $inspection, $userId, $note): OrderReturn {
            /** @var Order $order */
            $order = Order::query()->with('items')->lockForUpdate()->findOrFail($return->order_id);
            /** @var OrderReturn $locked */
            $locked = OrderReturn::query()->with('items')->lockForUpdate()->findOrFail($return->id);

            if (! $locked->status->canTransitionTo(ReturnStatus::Inspected)) {
                throw ReturnNotAllowed::returnStatus($locked->number, $locked->status->value, ReturnStatus::Inspected->value);
            }

            $errors = [];
            foreach ($locked->items->values() as $i => $line) {
                $result = $inspection[$line->order_item_id] ?? null;
                if ($result === null || $result['resellable'] < 0 || $result['damaged'] < 0
                    || $line->quantity !== $result['resellable'] + $result['damaged']) {
                    $errors["items.{$i}.resellable"] = __('returns.inspection_mismatch', ['quantity' => $line->quantity]);
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            /** @var array<int, OrderItem> $items */
            $items = $order->items->keyBy('id')->all();
            $restock = [];
            $returnedCost = Money::zero();
            $damagedCost = Money::zero();
            $goods = Money::zero();
            $refundLines = [];

            foreach ($locked->items as $line) {
                $item = $items[$line->order_item_id];
                ['resellable' => $resellable, 'damaged' => $damaged] = $inspection[$line->order_item_id];

                $line->quantity_resellable = $resellable;
                $line->quantity_damaged = $damaged;
                $line->save();

                $amount = $this->calculator->forUnits($order, $item, $item->quantity_refunded, $line->quantity);
                $refundLines[$item->id] = ['quantity' => $line->quantity, 'amount' => $amount];
                $goods = $goods->plus($amount);

                $returnedCost = $returnedCost->plus($item->cogs($line->quantity));
                $damagedCost = $damagedCost->plus($item->cogs($damaged));

                if ($item->variant_id !== null) {
                    $restock[] = [
                        'variant_id' => $item->variant_id,
                        'resellable' => $resellable,
                        'damaged' => $damaged,
                        'unit_cost' => $item->cost_price_snapshot,
                    ];
                }

                $item->quantity_returned += $line->quantity;
                $item->save();
            }

            $this->restock->handle($restock, OrderReturn::REFERENCE_TYPE, $locked->id, $userId);

            $refund = $this->openRefund($order, $locked, $goods, $refundLines, $userId);

            $locked->status = $refund === null ? ReturnStatus::Closed : ReturnStatus::Inspected;
            $locked->inspected_by = $userId;
            $locked->inspected_at = now();
            $locked->closed_at = $refund === null ? now() : null;
            $locked->refund_id = $refund?->id;
            $locked->note = $note !== null && trim($note) !== '' ? trim($note) : $locked->note;
            $locked->save();

            $this->moveOrder($order, $locked, $userId);

            OrderEvent::query()->create([
                'order_id' => $order->id,
                'type' => OrderEventType::ReturnInspected,
                'description' => $locked->number,
                'meta' => [
                    'return_id' => $locked->id,
                    'refund_id' => $refund?->id,
                    'refund_amount' => $refund?->amount->toDecimal(),
                ],
                'user_id' => $userId,
                'is_customer_visible' => false,
            ]);

            ReturnInspected::dispatch(
                $locked->id,
                $order->id,
                $returnedCost->toDecimal(),
                $damagedCost->toDecimal(),
                DhakaDate::today(),
                $order->delivered_at !== null,
                $refund?->id,
            );

            return $locked->load(['items', 'refund.items']);
        });
    }

    /** @param  array<int, array{quantity: int, amount: Money}>  $lines */
    private function openRefund(Order $order, OrderReturn $return, Money $goods, array $lines, ?int $userId): ?Refund
    {
        $payment = $this->quantities->capturedPayment($order->id);
        $shipping = $return->refund_shipping && ! $this->shippingRefunded($order->id)
            ? $this->calculator->shipping($order)
            : Money::zero();
        $computed = $goods->plus($shipping);
        $refundable = $this->quantities->refundable($order->id);

        if ($payment === null || $refundable->isZero() || $computed->isZero()) {
            return null;
        }

        $capped = $computed->greaterThan($refundable);
        $amount = $capped ? $refundable : $computed;
        $everythingBack = $order->items->every(static fn (OrderItem $i): bool => $i->quantity_returned >= $i->quantity);

        $refund = Refund::query()->create([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'amount' => $amount,
            'shipping_amount' => $capped && $shipping->greaterThan($amount) ? $amount : $shipping,
            'reason' => mb_substr($return->number.' · '.$return->reason->value.($return->reason_note !== null ? ' — '.$return->reason_note : ''), 0, 500),
            'type' => $everythingBack && $amount->equals($refundable) ? RefundType::Full : RefundType::Partial,
            'method' => $payment->method,
            'status' => RefundStatus::Requested,
            'restock' => $return->items->contains(static fn ($l): bool => ($l->quantity_resellable ?? 0) > 0),
            'requested_by' => $userId,
            'note' => $capped ? "Capped at the ৳{$refundable} still refundable (computed ৳{$computed})." : null,
        ]);

        foreach ($lines as $itemId => $line) {
            $refund->items()->create(['order_item_id' => $itemId, 'quantity' => $line['quantity'], 'amount' => $line['amount']]);
            OrderItem::query()->whereKey($itemId)->increment('quantity_refunded', $line['quantity']);
        }

        AuditSensitive::query()->create([
            'user_id' => $userId,
            'action' => AuditAction::RefundCreated,
            'model_type' => $refund->getMorphClass(),
            'model_id' => $refund->id,
            'before' => null,
            'after' => ['order_id' => $order->id, 'amount' => $amount->toDecimal(), 'return' => $return->number],
        ]);

        return $refund;
    }

    private function shippingRefunded(int $orderId): bool
    {
        return Refund::query()->where('order_id', $orderId)
            ->where('status', '!=', RefundStatus::Rejected->value)
            ->where('shipping_amount', '>', 0)
            ->exists();
    }

    private function moveOrder(Order $order, OrderReturn $return, ?int $userId): void
    {
        $order->load('items');
        $everythingBack = $order->items->every(static fn (OrderItem $i): bool => $i->quantity_returned >= $i->quantity);
        $target = $everythingBack ? OrderStatus::Returned : OrderStatus::PartiallyReturned;

        if ($order->status !== $target) {
            $order = $this->transitions->transition(
                $order, $target, $userId,
                attributes: ['return_reason' => $return->reason->value],
                meta: ['return_id' => $return->id],
                description: $return->number,
            );
        }

        // A delivered order is fulfilled; one the dispatch flow never marked (no courier
        // integration yet) cannot move to returned, and is left for that flow to settle.
        if ($everythingBack && $order->fulfillment_status !== FulfillmentStatus::Returned
            && $order->fulfillment_status->canTransitionTo(FulfillmentStatus::Returned)) {
            $this->transitions->transitionFulfillment($order, FulfillmentStatus::Returned, $userId);
        }
    }
}
