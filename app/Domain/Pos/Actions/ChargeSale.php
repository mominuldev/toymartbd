<?php

declare(strict_types=1);

namespace App\Domain\Pos\Actions;

use App\Domain\Inventory\Actions\ConsumeReservedStock;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Services\StockReservations;
use App\Domain\Marketing\Exceptions\CouponRejected;
use App\Domain\Marketing\Exceptions\PromotionRejected;
use App\Domain\Marketing\Services\CouponUsages;
use App\Domain\Marketing\Services\PromotionUsages;
use App\Domain\Ordering\Actions\SyncOrderPaymentStatus;
use App\Domain\Ordering\Checkout\OrderItemSnapshots;
use App\Domain\Ordering\Checkout\PriceChanged;
use App\Domain\Ordering\Enums\FulfillmentStatus;
use App\Domain\Ordering\Enums\OrderSource;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\VerificationStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\Services\OrderDispatcher;
use App\Domain\Ordering\Services\OrderNumberGenerator;
use App\Domain\Ordering\Services\OrderTransitions;
use App\Domain\Payment\Services\PaymentTransitions;
use App\Domain\Pos\DTOs\SaleData;
use App\Domain\Pos\Enums\CounterTender;
use App\Domain\Pos\Exceptions\PosRefused;
use App\Domain\Pos\Jobs\RecordPosTendersInLedger;
use App\Domain\Pos\Models\PosSale;
use App\Domain\Pos\Models\PosSession;
use App\Domain\Pos\Services\PosCustomers;
use App\Domain\Pos\Services\PosDues;
use App\Domain\Pos\Services\PosQuotes;
use App\Domain\Pos\Tenders\TenderRegistry;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Charge (Figma B · 33 "Charge ৳3,455") — one counter sale, ONE transaction (rule 3):
 *
 *   1. lock the drawer session; it must be open (a close waits for us, or we see it closed);
 *      a sale already charged with this Idempotency-Key on this drawer is returned as is;
 *   2. re-price the lines through the checkout pipeline (PosQuotes, rule 1) and refuse on any
 *      blocker; refuse when the total differs from the one the cashier saw (`price-changed`);
 *   3. plan the tenders (TenderPlanner): split allowed, non-cash needs a reference, change is
 *      computed here and only from cash; refuse unless they settle the total exactly. A `due`
 *      tender (owner decision 2026-11-15) settles part of the total with no money: it needs
 *      `pos.sell_on_due` and a known customer (PosDues);
 *   4. reserve the lines at the SESSION'S BRANCH — FOR UPDATE, ascending variant id, all or
 *      nothing (rule 9); short anywhere → InsufficientStock, nothing written;
 *   5. customer by phone, or the anonymous walk-in row (PosCustomers) — locked FOR UPDATE.
 *      With due, the guards run again on the LOCKED customer and a locking read of their open
 *      POS orders, so two concurrent due sales for one customer cannot both pass the limit;
 *   6. the order: source `pos`, at the branch's address, already handed over — so it is
 *      written in `shipped` / `fulfilled` and moved to `delivered` below through the state
 *      machine; items with `cost_price_snapshot` from the BRANCH's average cost (rule 7);
 *   7. consume the reservation as `sale_out` movements at the branch (Σ movements = on_hand);
 *   8. one payment per money tender through PaymentTransitions (pending → paid by the tender's
 *      driver; rule 5) — none for due — then the order's payment_status re-derived (→ paid, or
 *      partially paid / unpaid with `due_total` > 0 when part of it is on due);
 *   9. coupon and promotion usage under their own locks (limits re-checked);
 *  10. the `placed` timeline row, then shipped → delivered: OrderDelivered (after commit)
 *      makes Finance recognise sale revenue, discount and COGS on the Dhaka day of sale —
 *      the existing, idempotent delivery path, so a POS sale's P&L is built like any other;
 *  11. the pos_sales row (cash tendered, change, due, key). After commit, the paid tenders are
 *      booked as cash (`pos_receipt`, is_cash_movement, never revenue — rule 10) by a queued
 *      job. The due part books nothing: it is revenue (recognised on delivery) not yet cash.
 *
 * No external call anywhere (rule 4): tenders are manual, no gateway is live.
 *
 * @throws ValidationException field-level blockers (422)
 * @throws PriceChanged the total moved since the cashier's quote (409)
 * @throws InsufficientStock the branch cannot cover a line (409)
 * @throws CouponRejected|PromotionRejected a limit ran out since the quote
 * @throws PosRefused the drawer is closed (409)
 */
final readonly class ChargeSale
{
    private const int ATTEMPTS = 3;

    public function __construct(
        private PosQuotes $quotes,
        private PosCustomers $customers,
        private StockReservations $reservations,
        private ConsumeReservedStock $consume,
        private OrderItemSnapshots $snapshots,
        private OrderNumberGenerator $numbers,
        private OrderTransitions $transitions,
        private PaymentTransitions $payments,
        private SyncOrderPaymentStatus $syncPayment,
        private CouponUsages $couponUsages,
        private PromotionUsages $promotionUsages,
        private TenderRegistry $tenders,
        private PosDues $dues,
    ) {}

    public function handle(SaleData $data): PosSale
    {
        $this->numbers->prepare();

        try {
            return DB::transaction(fn (): PosSale => $this->charge($data), self::ATTEMPTS);
        } catch (UniqueConstraintViolationException $e) {
            // The same key committed by a concurrent request on this drawer: return that sale.
            $existing = $data->idempotencyKey === null ? null : PosSale::query()
                ->where('session_id', $data->sessionId)->where('idempotency_key', $data->idempotencyKey)->first();

            return $existing ?? throw $e;
        }
    }

    private function charge(SaleData $data): PosSale
    {
        /** @var PosSession $session */
        $session = PosSession::query()->with('location')->lockForUpdate()->findOrFail($data->sessionId);
        if (! $session->isOpen()) {
            throw PosRefused::sessionClosed();
        }
        if ($data->idempotencyKey !== null) {
            $existing = PosSale::query()->where('session_id', $session->id)->where('idempotency_key', $data->idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        // 2–3. Price and plan, from current data only.
        $quote = $this->quotes->quote($session->location_id, $data->lines, $data->couponCode, $data->phone, $data->tenders, $data->customerName, $data->canSellOnDue, $data->discount);
        if ($quote->blockers !== []) {
            throw self::invalid($quote->blockers);
        }
        $breakdown = $quote->breakdown;
        if (! $breakdown->grandTotal->equals($data->expectedTotal)) {
            throw new PriceChanged($data->expectedTotal, $breakdown->grandTotal);
        }
        $plan = $quote->tenders;
        if ($plan === null || $plan->errors !== []) {
            throw self::invalid($plan->errors ?? [['field' => 'tenders', 'message' => (string) __('pos.tender.short')]]);
        }
        if (! $plan->remaining->isZero()) {
            throw self::invalid([['field' => 'tenders', 'message' => (string) __('pos.tender.short_by', ['amount' => $plan->remaining->toDecimal()])]]);
        }

        // 4. Branch stock, locked in ascending variant id; all or nothing.
        $lines = [];
        foreach ($breakdown->lines as $line) {
            $lines[$line->variant->id] = $line->quantity;
        }
        ksort($lines);
        $locationId = $session->location_id;
        $this->reservations->reserve($lines, $locationId);

        // 5. Customer, row-locked. Due: re-check the guards on the locked row (the limit with a
        //    locking read — the quote above read a snapshot).
        $customer = $this->customers->forSale($data->phone, $data->customerName);
        if ($plan->hasDue()) {
            $errors = $this->dues->guard($plan, $data->canSellOnDue, $data->phone !== null, $customer, $data->customerName, $this->dues->openDue($customer->id, lock: true));
            if ($errors !== []) {
                throw self::invalid($errors);
            }
        }

        // 6. The order, handed over at the counter.
        $location = $session->location;
        $location->loadMissing(['division', 'district', 'upazila']);
        $now = now();
        $coupon = $breakdown->coupon?->applied() === true ? $breakdown->coupon : null;
        $order = Order::query()->create([
            'number' => $this->numbers->next(),
            'customer_id' => $customer->id,
            'source' => OrderSource::Pos,
            'channel_reference' => 'pos_session:'.$session->id,
            'payment_method' => $plan->payments[0]['method']->value ?? null,
            'status' => OrderStatus::Shipped,
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
            'subtotal' => $breakdown->subtotal,
            'discount_total' => $breakdown->discountTotal(),
            'coupon_code' => $coupon?->coupon?->code,
            'coupon_discount' => $breakdown->couponDiscount,
            'promotion_discount' => $breakdown->promotionDiscount,
            'manual_discount' => $breakdown->manualDiscount,
            'shipping_charge' => Money::zero(),
            'tax_total' => Money::zero(),
            'grand_total' => $breakdown->grandTotal,
            'due_total' => $breakdown->grandTotal,
            'shipping_name' => $customer->name,
            'shipping_phone' => $customer->phone,
            'shipping_division_id' => $location->division_id,
            'shipping_district_id' => $location->district_id,
            'shipping_upazila_id' => $location->upazila_id,
            'shipping_division' => $location->division?->name,
            'shipping_district' => $location->district?->name,
            'shipping_upazila' => $location->upazila?->name,
            'shipping_area' => $location->area,
            'shipping_address' => $location->address_line ?? $location->name,
            'shipping_landmark' => $location->landmark,
            'internal_note' => 'POS · '.$location->code.' · '.$session->register,
            'verification_status' => VerificationStatus::NotRequired,
            'placed_at' => $now,
            'confirmed_at' => $now,
            'packed_at' => $now,
            'shipped_at' => $now,
            'created_by' => $data->cashierId,
        ]);

        $consume = [];
        foreach ($this->snapshots->build($breakdown->lines, $locationId) as $item) {
            OrderItem::query()->create(['order_id' => $order->id, 'quantity_shipped' => $item['quantity']] + $item);
            $consume[] = ['variant_id' => (int) $item['variant_id'], 'quantity' => (int) $item['quantity'], 'unit_cost' => $item['cost_price_snapshot']];
        }

        // 7. The shelf: reservation → sale_out at the branch.
        usort($consume, static fn (array $a, array $b): int => $a['variant_id'] <=> $b['variant_id']);
        $this->consume->handle($consume, OrderDispatcher::STOCK_REFERENCE, $order->id, $data->cashierId, $locationId);

        // 8. Payments, one per money tender, each through its driver (due writes none).
        foreach ($plan->payments as $row) {
            $payment = $this->payments->open($order->id, $row['method'], $row['amount']);
            $result = $this->tenders->for(CounterTender::from($row['method']->value))->capture($payment, $row['amount'], $row['reference'], $data->cashierId);
            $this->payments->apply($payment, $result, $data->cashierId);
        }

        // 9. Discount usage, limits re-checked under lock. A discount implies a phone (PosQuotes).
        if ($data->phone !== null) {
            if ($coupon?->coupon !== null) {
                $this->couponUsages->record($coupon->coupon, $order->id, $data->phone, $breakdown->couponDiscount, $customer->id);
            }
            // Free-delivery promotions do not apply at the counter (nothing is delivered): not used.
            $applied = array_values(array_filter($breakdown->promotions, static fn ($p): bool => ! $p->freeShipping));
            $this->promotionUsages->record($applied, $order->id, $data->phone, $customer->id);
        }

        // 10. Timeline, then handed over: OrderDelivered → revenue recognition (after commit).
        $this->transitions->placed($order, $data->cashierId);
        $order = $this->transitions->transition(
            $order,
            OrderStatus::Delivered,
            $data->cashierId,
            meta: ['pos_session_id' => $session->id, 'location_id' => $locationId],
            description: 'Sold at the counter',
        );
        $this->syncPayment->handle($order->id);

        // 11. The counter's own record.
        $sale = PosSale::query()->create([
            'session_id' => $session->id,
            'order_id' => $order->id,
            'idempotency_key' => $data->idempotencyKey,
            'cashier_id' => $data->cashierId,
            'grand_total' => $breakdown->grandTotal,
            'manual_discount' => $breakdown->manualDiscount,
            'discount_type' => $breakdown->manualDiscount->isZero() ? null : $data->discount?->type,
            'discount_value' => $breakdown->manualDiscount->isZero() || $data->discount === null ? null : Money::of($data->discount->value),
            'discount_reason' => $breakdown->manualDiscount->isZero() ? null : $breakdown->manualDiscountReason,
            'tendered_total' => $plan->tendered,
            'cash_tendered' => $plan->cashTendered,
            'change_due' => $plan->change,
            'due_amount' => $plan->dueAmount(),
        ]);

        RecordPosTendersInLedger::dispatch($sale->id)->afterCommit();

        return $sale;
    }

    /** @param  list<array{field: string, message: string}>  $errors */
    private static function invalid(array $errors): ValidationException
    {
        $messages = [];
        foreach ($errors as $error) {
            $messages[$error['field']][] = $error['message'];
        }

        return ValidationException::withMessages($messages);
    }
}
