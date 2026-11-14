<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Cart\Actions\MarkCartConverted;
use App\Domain\Cart\DTOs\PriceBreakdown;
use App\Domain\Cart\DTOs\PricingInput;
use App\Domain\Cart\Exceptions\CartNotFound;
use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Services\CartResolver;
use App\Domain\Cart\Services\PricingPipeline;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Services\StockReservations;
use App\Domain\Marketing\Exceptions\CouponRejected;
use App\Domain\Marketing\Exceptions\PromotionRejected;
use App\Domain\Marketing\Services\CouponUsages;
use App\Domain\Marketing\Services\PromotionUsages;
use App\Domain\Ordering\Checkout\CheckoutCustomers;
use App\Domain\Ordering\Checkout\CheckoutData;
use App\Domain\Ordering\Checkout\CheckoutNotReady;
use App\Domain\Ordering\Checkout\OrderItemSnapshots;
use App\Domain\Ordering\Checkout\PriceChanged;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\RiskLevel;
use App\Domain\Ordering\Enums\VerificationStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\Services\OrderNumberGenerator;
use App\Domain\Ordering\Services\OrderTransitions;
use App\Domain\Payment\Services\PaymentTransitions;
use App\Domain\Risk\DTOs\RiskAssessment;
use App\Domain\Risk\DTOs\RiskInput;
use App\Domain\Risk\Services\OrderRiskAssessor;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Checkout (P4-ORD-02) — DATABASE-DESIGN §4.1 as one transaction; docs/api/orders.md
 * "PlaceOrder as built" walks the steps. In short:
 *
 *   lock the cart → re-price it (PricingPipeline, with the phone, so per-phone coupon
 *   limits hold) → refuse unless checkout-ready → refuse unless the total equals what
 *   the customer saw → reserve stock (FOR UPDATE, ascending variant id, all or nothing)
 *   → customer + address → number → order + items (cost snapshot) → COD payment →
 *   coupon usage → risk (hold, never cancel) → `placed` event. COMMIT. Then the cart
 *   is marked converted.
 *
 * Nothing in here makes an external call (rule 4). An online payment's InitiatePayment
 * (driver HTTP) belongs after commit, in the caller, once a gateway exists (P4-PAY-02).
 *
 * @throws CartNotFound no open cart for the token, or it already became an order
 * @throws CheckoutNotReady empty cart, unsellable item, coupon no longer valid, address not serviced
 * @throws PriceChanged the re-priced total differs from the expected total
 * @throws InsufficientStock some line cannot be reserved — the whole order fails
 * @throws CouponRejected a coupon limit was reached since pricing (re-checked under lock)
 * @throws PromotionRejected a promotion limit, budget or flash allotment ran out since pricing
 */
final readonly class PlaceOrder
{
    /**
     * A deadlock or lock-wait victim is retried from the top (the whole closure is
     * transactional and its events fire only after commit). Ordered locking (rule 9)
     * makes this rare; it must never surface to a customer as a 500.
     */
    private const int ATTEMPTS = 3;

    public function __construct(
        private CartResolver $carts,
        private PricingPipeline $pricing,
        private StockReservations $reservations,
        private CheckoutCustomers $customers,
        private OrderItemSnapshots $snapshots,
        private OrderNumberGenerator $numbers,
        private PaymentTransitions $payments,
        private CouponUsages $couponUsages,
        private OrderRiskAssessor $risk,
        private OrderTransitions $transitions,
        private MarkCartConverted $markCartConverted,
        private PromotionUsages $promotionUsages,
    ) {}

    public function handle(CheckoutData $data): Order
    {
        // The order-number counter row exists before the transaction locks it (P9 F-1): an
        // INSERT IGNORE on it inside the transaction deadlocks two concurrent checkouts.
        $this->numbers->prepare();

        $order = DB::transaction(function () use ($data): Order {
            // 1. The cart, locked: a second checkout of the same cart waits here, then
            //    finds it already ordered.
            $cart = $this->carts->lock($data->cartToken);
            if (Order::query()->where('cart_id', $cart->id)->exists()) {
                throw new CartNotFound;
            }
            $cart->load('coupon');

            // 2. Re-price from current data (never the client's figures).
            $existing = $this->customers->find($data);
            $breakdown = $this->pricing->price(new PricingInput(
                lines: $cart->lines(),
                couponCode: $cart->coupon?->code,
                district: $data->district,
                upazila: $data->upazila,
                phone: $data->phone,
                customerId: $existing?->id,
            ));
            self::assertReady($cart, $breakdown, $data);
            if (! $breakdown->grandTotal->equals($data->expectedTotal)) {
                throw new PriceChanged($data->expectedTotal, $breakdown->grandTotal);
            }
            /** @var Money $shipping isCheckoutReady() guarantees it */
            $shipping = $breakdown->shippingCharge;

            // 3. Reserve stock: all or nothing, ascending variant id (rule 9).
            $lines = [];
            foreach ($breakdown->lines as $line) {
                $lines[$line->variant->id] = $line->quantity;
            }
            ksort($lines);
            $locationId = StockLocation::defaultId();
            $this->reservations->reserve($lines, $locationId);

            // 4. Customer by phone + address book.
            $customer = $this->customers->findOrCreate($data);
            $this->customers->address($customer, $data);

            // 10 (computed early so the order row is written once). Advisory only: Risk reads
            //    the phone's history and decides holds (rules 23, 24b, 33b); it never cancels.
            $risk = $this->risk->assess(new RiskInput(
                phone: $data->phone,
                orderValue: $breakdown->grandTotal,
                codAdvanceRequired: $breakdown->delivery?->codAdvance !== null,
                addressLine: $data->addressLine,
                hasLocality: $data->upazila !== null || trim($data->area) !== '',
            ));

            // 5–6. Number and order.
            $coupon = $breakdown->coupon?->applied() === true ? $breakdown->coupon : null;
            $discountTotal = $breakdown->discountTotal();
            $order = Order::query()->create([
                'number' => $this->numbers->next(),
                'customer_id' => $customer->id,
                'cart_id' => $cart->id,
                'source' => $data->source,
                'payment_method' => $data->paymentMethod->value,
                'subtotal' => $breakdown->subtotal,
                'discount_total' => $discountTotal,
                'coupon_code' => $coupon?->coupon?->code,
                'coupon_discount' => $breakdown->couponDiscount,
                'promotion_discount' => $breakdown->promotionDiscount,
                'shipping_charge' => $shipping,
                'tax_total' => Money::zero(),
                // §7.3: grand_total = subtotal − discount_total + shipping_charge + tax_total
                'grand_total' => $breakdown->grandTotal,
                'due_total' => $breakdown->grandTotal,
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
                'shipping_postcode' => $data->postcode,
                'customer_note' => $data->customerNote,
                'risk_score' => $risk->score,
                'risk_level' => RiskLevel::from($risk->level),
                'risk_flags' => $risk->toFlags(),
                'verification_status' => $risk->shouldHold() ? VerificationStatus::Pending : VerificationStatus::NotRequired,
                'placed_at' => now(),
                'ip_address' => $data->ipAddress,
                'user_agent' => $data->userAgent === null ? null : Str::limit($data->userAgent, 497),
                'utm_source' => $data->utm['source'] ?? null,
                'utm_medium' => $data->utm['medium'] ?? null,
                'utm_campaign' => $data->utm['campaign'] ?? null,
            ]);

            // 7. Items with every snapshot, cost_price_snapshot written once (rule 7).
            //    `recommendation_source` comes from the cart line that added it (rule 25d).
            foreach ($this->snapshots->build($breakdown->lines, $locationId, $cart->recommendationSources()) as $item) {
                OrderItem::query()->create(['order_id' => $order->id] + $item);
            }

            // 8. Payment row, `pending` for every method (COD stays unpaid until collection).
            $this->payments->open($order->id, $data->paymentMethod, $breakdown->grandTotal);

            // 9. Coupon usage — re-checks total and per-phone limits under the coupon lock.
            if ($coupon?->coupon !== null) {
                $this->couponUsages->record($coupon->coupon, $order->id, $data->phone, $breakdown->couponDiscount, $customer->id);
            }

            // 9b. Promotion usage (P7) — limits, budget and flash allotments re-checked under lock.
            $this->promotionUsages->record($breakdown->promotions, $order->id, $data->phone, $customer->id);

            // 11. Timeline + OrderPlaced (after commit); then hold if risk says so (never cancel).
            $this->transitions->placed($order);
            if ($risk->shouldHold()) {
                $order = $this->transitions->transition(
                    $order,
                    OrderStatus::OnHold,
                    meta: ['reasons' => $risk->holdReasons],
                    description: self::holdDescription($risk),
                );
            }

            return $order;
        }, self::ATTEMPTS);

        // 12. After commit: the cart closes (its token stops resolving as a cart).
        if ($order->cart_id !== null) {
            $this->markCartConverted->handle($order->cart_id, $order->id);
        }

        return $order->refresh();
    }

    private static function assertReady(Cart $cart, PriceBreakdown $breakdown, CheckoutData $data): void
    {
        if ($breakdown->isCheckoutReady()) {
            return;
        }

        $errors = [];
        if ($cart->items->isEmpty()) {
            $errors[] = ['field' => 'cart', 'message' => (string) __('checkout.cart_empty')];
        }
        foreach ($breakdown->unavailableVariantIds as $variantId) {
            $errors[] = ['field' => 'items.'.$variantId, 'message' => (string) __('cart.variant.unavailable')];
        }
        if ($breakdown->coupon !== null && ! $breakdown->coupon->applied()) {
            $errors[] = ['field' => 'coupon', 'message' => (string) $breakdown->coupon->message()];
        }
        if ($breakdown->shippingCharge === null) {
            $errors[] = ['field' => $data->upazila !== null ? 'upazila_id' : 'district_id', 'message' => (string) __('checkout.not_serviced')];
        }

        throw new CheckoutNotReady($errors !== [] ? $errors : [['field' => 'cart', 'message' => (string) __('checkout.cart_empty')]]);
    }

    private static function holdDescription(RiskAssessment $risk): string
    {
        return 'Held for verification: '.implode(', ', $risk->holdReasons);
    }
}
