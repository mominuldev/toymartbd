<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions\Returns;

use App\Domain\Ordering\Enums\OrderEventType;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\ReturnReason;
use App\Domain\Ordering\Enums\ReturnStatus;
use App\Domain\Ordering\Exceptions\ReturnNotAllowed;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderEvent;
use App\Domain\Ordering\Models\OrderReturn;
use App\Domain\Ordering\Services\ReturnQuantities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Open a return (RMA) against a delivered order (DATABASE-DESIGN §4.7, PRD O-21). In one
 * transaction, with the order locked FOR UPDATE so two staff cannot both return the last
 * unit:
 *
 *   assert the order is delivered or partially returned →
 *   assert each quantity ≤ delivered − already returned − held by other open returns →
 *   assert the delivery charge is not already being refunded →
 *   insert the return + lines, number it RMA-YY-NNNNN, append an order_events row.
 *
 * Nothing moves yet: stock, order status and money move at inspection.
 */
final readonly class RequestReturn
{
    public function __construct(private ReturnQuantities $quantities) {}

    /**
     * @param  array<int, int>  $lines  order item id → quantity
     *
     * @throws ValidationException over-return, unknown line, or delivery already refunded
     * @throws ReturnNotAllowed
     */
    public function handle(
        Order $order,
        array $lines,
        ReturnReason $reason,
        ?string $reasonNote = null,
        bool $refundShipping = false,
        ?int $userId = null,
    ): OrderReturn {
        $reasonNote = $reasonNote !== null && trim($reasonNote) !== '' ? trim($reasonNote) : null;
        if ($reason->requiresNote() && $reasonNote === null) {
            throw ValidationException::withMessages(['reason_note' => __('validation.required', ['attribute' => 'reason note'])]);
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['items' => __('validation.required', ['attribute' => 'items'])]);
        }

        return DB::transaction(function () use ($order, $lines, $reason, $reasonNote, $refundShipping, $userId): OrderReturn {
            /** @var Order $locked */
            $locked = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);

            if (! in_array($locked->status, [OrderStatus::Delivered, OrderStatus::PartiallyReturned], true)) {
                throw ReturnNotAllowed::orderStatus($locked->number, $locked->status->value);
            }

            $returnable = $this->quantities->returnable($locked);
            $errors = [];
            $index = 0;
            foreach ($lines as $itemId => $quantity) {
                if (! array_key_exists($itemId, $returnable)) {
                    $errors["items.{$index}.order_item_id"] = __('returns.unknown_line');
                } elseif ($quantity < 1 || $quantity > $returnable[$itemId]) {
                    $errors["items.{$index}.quantity"] = __('returns.over_return', ['max' => $returnable[$itemId]]);
                }
                $index++;
            }
            if ($refundShipping && $this->quantities->shippingClaimed($locked->id)) {
                $errors['refund_shipping'] = __('returns.shipping_already_refunded');
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $return = OrderReturn::query()->create([
                'number' => 'P-'.Str::random(20),
                'order_id' => $locked->id,
                'status' => ReturnStatus::Requested,
                'reason' => $reason,
                'reason_note' => $reasonNote,
                'refund_shipping' => $refundShipping,
                'requested_by' => $userId,
            ]);
            $return->number = sprintf('RMA-%s-%05d', now()->format('y'), $return->id);
            $return->save();

            ksort($lines);
            foreach ($lines as $itemId => $quantity) {
                $return->items()->create(['order_item_id' => $itemId, 'quantity' => $quantity]);
            }

            OrderEvent::query()->create([
                'order_id' => $locked->id,
                'type' => OrderEventType::ReturnRequested,
                'description' => $return->number,
                'meta' => ['return_id' => $return->id, 'reason' => $reason->value],
                'user_id' => $userId,
                'is_customer_visible' => false,
            ]);

            return $return->load('items');
        });
    }
}
