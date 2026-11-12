<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Cart\DTOs\LineBreakdown;
use App\Domain\Cart\DTOs\PriceBreakdown;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Services\StockReservations;
use App\Domain\Marketing\Exceptions\CouponRejected;
use App\Domain\Marketing\Services\CouponUsages;
use App\Domain\Ordering\Checkout\CheckoutNotReady;
use App\Domain\Ordering\Checkout\OrderItemSnapshots;
use App\Domain\Ordering\Checkout\PriceChanged;
use App\Domain\Ordering\DTOs\OrderEditData;
use App\Domain\Ordering\Enums\OrderEventType;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Events\OrderEdited;
use App\Domain\Ordering\Exceptions\OrderNotEditable;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderEvent;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\Services\OrderQuotes;
use App\Domain\Payment\Services\PaymentTransitions;
use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * Edit an order after placement (P4-ORD-07): lines added, removed or re-quantified, the
 * recipient and address changed, the delivery charge re-quoted. One transaction:
 *
 *   lock the order → refuse unless editable (status below) and no money has moved →
 *   re-price through THE pricing pipeline (OrderQuotes::forEdit) → refuse unless
 *   order-ready and equal to the total the operator was shown → adjust the reservation
 *   by the per-variant difference (one ascending-variant-id lock pass, rule 9, all or
 *   nothing) → rewrite lines → re-record coupon usage → order totals + address → pending
 *   payment re-amounted → one `edited` order_events row with before/after → OrderEdited.
 *
 * Lines: an existing line keeps its `cost_price_snapshot` forever (rule 7) — only its
 * quantity, price and discount follow the new quote. A removed line is never deleted
 * (order items are permanent): it drops to quantity 0. A variant added (or re-added after
 * removal) gets a NEW line whose snapshot is the cost at the moment it was added.
 *
 * Editable statuses: pending, on_hold, confirmed, processing — the goods have not been
 * packed. From ready_to_ship (packed) on, the parcel would need unpacking: put it back
 * first, or cancel. Shipped and later are never editable (the way back is a return).
 *
 * The audit row never holds a full phone or street address (rule 20): the location
 * names (division … area) are stored before/after; the street line, landmark, name and
 * phones are recorded only as "changed".
 *
 * @throws OrderNotEditable wrong status, money already in flight, or a line whose variant is gone
 * @throws CheckoutNotReady no lines, unsellable line, coupon lost, address not serviced
 * @throws PriceChanged the re-priced total differs from `expectedTotal`
 * @throws InsufficientStock an increase cannot be reserved — nothing is written
 * @throws CouponRejected a coupon limit was reached (re-checked under the coupon lock)
 */
final readonly class EditOrder
{
    /** @var list<OrderStatus> */
    public const array EDITABLE = [OrderStatus::Pending, OrderStatus::OnHold, OrderStatus::Confirmed, OrderStatus::Processing];

    private const int ATTEMPTS = 3;

    public function __construct(
        private OrderQuotes $quotes,
        private StockReservations $reservations,
        private OrderItemSnapshots $snapshots,
        private CouponUsages $couponUsages,
        private PaymentTransitions $payments,
    ) {}

    public static function isEditable(Order $order): bool
    {
        return in_array($order->status, self::EDITABLE, true);
    }

    /** The re-quote an edit would produce, without writing anything (the edit screen's preview). */
    public function quote(Order $order, OrderEditData $data): PriceBreakdown
    {
        self::assertEditable($order);

        return $this->quotes->forEdit($order, self::normalise($data->lines), $data->district, $data->upazila);
    }

    public function handle(Order $order, OrderEditData $data, ?int $userId = null): Order
    {
        return DB::transaction(function () use ($order, $data, $userId): Order {
            /** @var Order $locked */
            $locked = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);
            self::assertEditable($locked);
            if (PaymentTransitions::hasMoneyInFlight($locked->id)) {
                throw OrderNotEditable::moneyInFlight($locked->number);
            }

            $newLines = self::normalise($data->lines);
            $breakdown = $this->quotes->forEdit($locked, $newLines, $data->district, $data->upazila);
            $blockers = OrderQuotes::blockers($breakdown, $data->upazila, requireCoupon: $locked->coupon_code !== null);
            if ($blockers !== []) {
                throw new CheckoutNotReady($blockers);
            }
            /** @var Money $shipping blockers() refused a null charge for a destination */
            $shipping = $breakdown->shippingCharge;
            if ($data->expectedTotal !== null && ! $breakdown->grandTotal->equals($data->expectedTotal)) {
                throw new PriceChanged($data->expectedTotal, $breakdown->grandTotal);
            }

            $before = self::snapshot($locked);

            // Reservation: the per-variant difference, one ascending lock pass (rule 9).
            $oldLines = $locked->reservedLines();
            $deltas = [];
            foreach (array_unique([...array_keys($oldLines), ...array_keys($newLines)]) as $variantId) {
                $deltas[$variantId] = ($newLines[$variantId] ?? 0) - ($oldLines[$variantId] ?? 0);
            }
            ksort($deltas);
            $locationId = StockLocation::defaultId();
            $this->reservations->adjust($deltas, $locationId);

            // Lines: existing ones keep their cost snapshot; new variants get a new line.
            $priced = [];
            foreach ($breakdown->lines as $line) {
                $priced[$line->variant->id] = $line;
            }
            $active = $locked->items->filter(static fn (OrderItem $i): bool => $i->quantity > 0)->keyBy('variant_id');
            foreach ($active as $variantId => $item) {
                $line = $priced[(int) $variantId] ?? null;
                $item->forceFill($line === null
                    ? ['quantity' => 0, 'discount' => Money::zero(), 'line_total' => Money::zero()]
                    : ['quantity' => $line->quantity, 'unit_price' => $line->unitPrice, 'discount' => $line->discount(), 'line_total' => $line->total])->save();
            }
            $added = array_values(array_filter($breakdown->lines, static fn (LineBreakdown $l): bool => ! $active->has($l->variant->id)));
            foreach ($this->snapshots->build($added, $locationId) as $row) {
                OrderItem::query()->create(['order_id' => $locked->id] + $row);
            }

            // Coupon usage follows the new discount; record() re-checks the limits under lock.
            $this->couponUsages->reverseForOrder($locked->id);
            $coupon = $breakdown->coupon?->applied() === true ? $breakdown->coupon->coupon : null;
            if ($coupon !== null) {
                $usagePhone = PhoneNumber::tryParse($locked->customer->phone) ?? $data->phone;
                $this->couponUsages->record($coupon, $locked->id, $usagePhone, $breakdown->couponDiscount, $locked->customer_id);
            }

            $locked->forceFill([
                'subtotal' => $breakdown->subtotal,
                'discount_total' => $breakdown->discountTotal(),
                'coupon_discount' => $breakdown->couponDiscount,
                'promotion_discount' => $breakdown->promotionDiscount,
                'shipping_charge' => $shipping,
                // §7.3: grand_total = subtotal − discount_total + shipping_charge + tax_total
                'grand_total' => $breakdown->grandTotal,
                'due_total' => $breakdown->grandTotal->minus($locked->paid_total),
                'shipping_name' => $data->name,
                'shipping_phone' => $data->phone->e164(),
                'shipping_alt_phone' => $data->altPhone?->e164(),
                'shipping_division_id' => $data->district->division_id,
                'shipping_district_id' => $data->district->id,
                'shipping_upazila_id' => $data->upazila?->id,
                'shipping_division' => $data->district->division->name,
                'shipping_district' => $data->district->name,
                'shipping_upazila' => $data->upazila?->name,
                'shipping_area' => $data->area,
                'shipping_address' => $data->addressLine,
                'shipping_landmark' => $data->landmark,
            ])->save();

            $locked->load('items');
            $after = self::snapshot($locked);
            $changed = self::changed($before, $after);
            if ($changed === []) {
                return $locked;
            }

            if (! $before['totals']['grand_total']->equals($breakdown->grandTotal)) {
                $this->payments->reamountPending($locked->id, $breakdown->grandTotal);
            }

            OrderEvent::query()->create([
                'order_id' => $locked->id,
                'type' => OrderEventType::Edited,
                'field' => null,
                'from_status' => $locked->status->value,
                'to_status' => $locked->status->value,
                'description' => $data->note,
                'meta' => ['changed' => $changed, 'before' => self::serialise($before), 'after' => self::serialise($after)],
                'user_id' => $userId,
                'is_customer_visible' => false,
            ]);
            OrderEdited::dispatch($locked->id, $locked->customer_id, $changed, $before['totals']['grand_total']->toDecimal(), $breakdown->grandTotal->toDecimal(), $userId);

            return $locked;
        }, self::ATTEMPTS);
    }

    private static function assertEditable(Order $order): void
    {
        if (! self::isEditable($order)) {
            throw OrderNotEditable::inStatus($order->number, $order->status->value);
        }
        foreach ($order->items as $item) {
            if ($item->quantity > 0 && $item->variant_id === null) {
                throw OrderNotEditable::variantGone($order->number, $item->sku);
            }
        }
    }

    /**
     * @param  array<int, int>  $lines
     * @return array<int, int> positive quantities, ascending variant id
     */
    private static function normalise(array $lines): array
    {
        $lines = array_filter($lines, static fn (int $q): bool => $q > 0);
        ksort($lines);

        return $lines;
    }

    /**
     * The audited state. Location names only — never the street line, landmark, name or
     * phone values (rule 20); those are compared through a hash that is not stored.
     *
     * @return array{lines: array<int, array{variant_id: int, sku: string, quantity: int, unit_price: string, line_total: string, cost_price_snapshot: string}>, totals: array<string, Money>, location: array<string, int|string|null>, private: array<string, string>}
     */
    private static function snapshot(Order $order): array
    {
        $lines = [];
        foreach ($order->items as $item) {
            if ($item->quantity > 0 && $item->variant_id !== null) {
                $lines[$item->id] = [
                    'variant_id' => $item->variant_id,
                    'sku' => $item->sku,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price->toDecimal(),
                    'line_total' => $item->line_total->toDecimal(),
                    'cost_price_snapshot' => (string) $item->cost_price_snapshot,
                ];
            }
        }

        return [
            'lines' => $lines,
            'totals' => [
                'subtotal' => $order->subtotal,
                'discount_total' => $order->discount_total,
                'shipping_charge' => $order->shipping_charge,
                'grand_total' => $order->grand_total,
            ],
            'location' => [
                'division' => $order->shipping_division,
                'district' => $order->shipping_district,
                'district_id' => $order->shipping_district_id,
                'upazila' => $order->shipping_upazila,
                'upazila_id' => $order->shipping_upazila_id,
                'area' => $order->shipping_area,
            ],
            'private' => [
                'name' => hash('sha256', (string) $order->shipping_name),
                'phone' => hash('sha256', (string) $order->shipping_phone.'|'.$order->shipping_alt_phone),
                'address_line' => hash('sha256', (string) $order->shipping_address),
                'landmark' => hash('sha256', (string) $order->shipping_landmark),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string> lines|address|recipient|totals, plus the private fields that changed
     */
    private static function changed(array $before, array $after): array
    {
        $changed = [];
        if ($before['lines'] !== $after['lines']) {
            $changed[] = 'lines';
        }
        if ($before['location'] !== $after['location']
            || $before['private']['address_line'] !== $after['private']['address_line']
            || $before['private']['landmark'] !== $after['private']['landmark']) {
            $changed[] = 'address';
        }
        if ($before['private']['name'] !== $after['private']['name'] || $before['private']['phone'] !== $after['private']['phone']) {
            $changed[] = 'recipient';
        }
        foreach ($before['totals'] as $key => $money) {
            if (! $money->equals($after['totals'][$key])) {
                $changed[] = 'totals';
                break;
            }
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed> JSON-safe, private fields as changed-flags only
     */
    private static function serialise(array $state): array
    {
        return [
            'lines' => array_values($state['lines']),
            'totals' => array_map(static fn (Money $m): string => $m->toDecimal(), $state['totals']),
            'location' => $state['location'],
        ];
    }
}
