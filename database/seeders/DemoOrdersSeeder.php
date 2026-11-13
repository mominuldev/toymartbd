<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Cart\Actions\AddCartLine;
use App\Domain\Cart\Actions\ApplyCoupon;
use App\Domain\Cart\Actions\SetCartContact;
use App\Domain\Cart\DTOs\PricingInput;
use App\Domain\Cart\Exceptions\CartNotFound;
use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Services\PricingPipeline;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Actions\RebuildCustomerAggregates;
use App\Domain\Customer\Models\Customer;
use App\Domain\Finance\Actions\ConfirmCourierSettlement;
use App\Domain\Finance\Actions\ImportCourierSettlement;
use App\Domain\Finance\Actions\RecordCodReceived;
use App\Domain\Finance\Actions\ReviewSettlementLine;
use App\Domain\Finance\DTOs\SettlementLine;
use App\Domain\Finance\Enums\SettlementReview;
use App\Domain\Finance\Enums\SettlementVariance;
use App\Domain\Finance\Models\CourierSettlementItem;
use App\Domain\Finance\Services\Receivables;
use App\Domain\Fulfilment\Actions\RefreshShipmentStatus;
use App\Domain\Fulfilment\Actions\RequestShipment;
use App\Domain\Fulfilment\Drivers\NullCourierGateway;
use App\Domain\Fulfilment\DTOs\BookingResult;
use App\Domain\Fulfilment\DTOs\CourierStatusResult;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Jobs\BookShipment;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\CourierRegistry;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Marketing\Exceptions\CouponRejected;
use App\Domain\Marketing\Exceptions\PromotionRejected;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Notification\Actions\DetectAbandonedCarts;
use App\Domain\Ordering\Actions\AddOrderNote;
use App\Domain\Ordering\Actions\CancelOrder;
use App\Domain\Ordering\Actions\EditOrder;
use App\Domain\Ordering\Actions\MarkOrderDelivered;
use App\Domain\Ordering\Actions\MarkOrderShipped;
use App\Domain\Ordering\Actions\PlaceManualOrder;
use App\Domain\Ordering\Actions\PlaceOrder;
use App\Domain\Ordering\Actions\RejectHeldOrder;
use App\Domain\Ordering\Actions\Returns\ApproveRefund;
use App\Domain\Ordering\Actions\Returns\InspectReturn;
use App\Domain\Ordering\Actions\Returns\PayRefund;
use App\Domain\Ordering\Actions\Returns\ReceiveReturn;
use App\Domain\Ordering\Actions\Returns\RejectRefund;
use App\Domain\Ordering\Actions\Returns\RejectReturn;
use App\Domain\Ordering\Actions\Returns\RequestReturn;
use App\Domain\Ordering\Actions\TransitionOrder;
use App\Domain\Ordering\Actions\VerifyHeldOrder;
use App\Domain\Ordering\Checkout\CheckoutData;
use App\Domain\Ordering\Checkout\CheckoutNotReady;
use App\Domain\Ordering\Checkout\PriceChanged;
use App\Domain\Ordering\DTOs\ManualOrderData;
use App\Domain\Ordering\DTOs\OrderEditData;
use App\Domain\Ordering\Enums\CancellationReason;
use App\Domain\Ordering\Enums\OrderSource;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\ReturnReason;
use App\Domain\Ordering\Enums\VerificationStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderReturn;
use App\Domain\Ordering\Services\OrderQuotes;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Models\Refund;
use App\Domain\Risk\Actions\LogVerificationCall;
use App\Domain\Risk\Actions\RefreshRiskProfile;
use App\Domain\Risk\Enums\CallOutcome;
use App\Models\Enums\Permission;
use App\Models\User;
use App\Support\Money;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Closure;
use Database\Seeders\Demo\DemoOrderPlaces;
use Database\Seeders\Demo\DemoOrderSandbox;
use Database\Seeders\Demo\DemoOrderTrack;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * ~60 days of Toy Mart BD orders (local/testing only, from DemoDataSeeder), driven through
 * the same Actions the storefront and admin call, so every admin screen, report and the
 * two build invariants see a business that really happened:
 *
 *  - **Placement.** Storefront checkouts (cart → coupon → PlaceOrder, some with UTM from a
 *    Facebook campaign) and staff phone / Facebook / WhatsApp orders (PlaceManualOrder,
 *    priced by OrderQuotes like the admin compose screen). Mostly the DemoCustomersSeeder
 *    accounts (repeat buyers), plus guests who become customers at checkout. Coupons
 *    WELCOME100 / TOY10 are used when DemoMarketingSeeder made them and they still apply;
 *    otherwise the order is placed without one (this seeder never creates a coupon).
 *  - **Lifecycle.** confirm (TransitionOrder) → processing / packed → shipped → delivered →
 *    completed; cancellations with reasons (CancelOrder); risk holds — a first order of
 *    ৳3,000+ from an unknown phone is held (rule 33b) — called (LogVerificationCall) and
 *    verified (VerifyHeldOrder) or rejected (RejectHeldOrder), a few still in the queue;
 *    edited orders (EditOrder); internal and customer-visible notes (AddOrderNote).
 *  - **Shipping.** Most parcels go out the manual way (MarkOrderShipped / MarkOrderDelivered:
 *    "Steadfast (portal)", own rider). Older ones go through the courier integration
 *    (RequestShipment → BookShipment → RefreshShipmentStatus) against NullCourierGateway
 *    registered as `steadfast` for this run only — the fixture driver the CI suite uses
 *    (rule 33a: no merchant account exists, so no booking ever leaves the machine). Only
 *    shipments that reach a final status (delivered / returned) are made this way, so the
 *    scheduled courier poll never finds an open demo consignment to ask Steadfast about.
 *    A refused parcel comes back as a courier return (RecordCourierReturn via ShipmentReturned).
 *  - **Money.** COD is collected on delivery and revenue recognised then (rule 10); cash
 *    arrives later — courier remittances imported, reviewed (one short line accepted) and
 *    confirmed (ImportCourierSettlement → ReviewSettlementLine → ConfirmCourierSettlement),
 *    the newest left unconfirmed, and manual remittances recorded for portal-shipped
 *    orders (RecordCodReceived). Online payment is not seeded: SSLCommerz is inactive and
 *    has no fixture path without HTTP.
 *  - **Returns.** RequestReturn → ReceiveReturn → InspectReturn → ApproveRefund → PayRefund
 *    (partial, full with the delivery charge, a damaged unit written off), one RejectReturn,
 *    one RejectRefund, one return still waiting to come back and one waiting for inspection.
 *  - **Carts.** A few abandoned carts with a contact (DetectAbandonedCarts — the recovery SMS
 *    is recorded, never sent), one of them recovered by its order, and a couple of open carts.
 *  - Finally every customer's aggregates and every phone's risk profile are rebuilt.
 *
 * Time: each step runs with the clock frozen at its own moment (Dhaka business hours),
 * steps in chronological order across all orders, so order numbers, timelines, the ledger
 * and the daily reports bucket by the day it "happened". Steps that would land in the
 * future are left out — that order simply is not that far along yet. A fixed random seed
 * makes every run produce the same business.
 *
 * Stock: lines are picked when the order is placed, only from variants that keep at least
 * STOCK_FLOOR units free at Dhaka HQ, so no order fails for stock and DemoInventorySeeder's
 * sold-out / low-stock states stay as they were.
 *
 * Runs inside DemoOrderSandbox (queue sync, outbound messages recorded but never sent,
 * search/revalidation silent). Idempotent: skipped entirely once any demo phone has an order.
 */
class DemoOrdersSeeder extends Seeder
{
    /** Courier code the NullCourierGateway stands in for (the default, active provider). */
    public const string COURIER = 'steadfast';

    /** Units every variant keeps free at the default location after any demo order. */
    public const int STOCK_FLOOR = 8;

    /** Orders per day of history, on average. */
    public const float ORDERS_PER_DAY = 2.3;

    /** Days ago (from today) that were busy: the 11.11 campaign, and a payday weekend. */
    public const array BUSY_DAYS = [2 => 3.0, 3 => 1.8, 23 => 2.2];

    /**
     * Guest buyers — no account; checkout creates them. phone → [name, lang, district,
     * upazila (null = city), area, line, landmark]. The first seven are reserved for the
     * risk-hold scenarios (their first order is large and from an unknown phone).
     *
     * @var array<string, array{string, string, string, string|null, string, string, string}>
     */
    public const array GUESTS = [
        '01716284039' => ['মোস্তফা কামাল', 'bn', 'Dhaka', null, 'মিরপুর ১', 'বাড়ি ১৯, রোড ২, সেকশন ১', 'মিরপুর ১ মাজার রোডের মুখে'],
        '01845602917' => ['Rakib Hasan', 'en', 'Chattogram', 'Sitakunda', 'Kumira', 'Hasan Villa, Kumira Bazar', 'Next to Kumira ghat road'],
        '01970318264' => ['শাহানা বেগম', 'bn', 'Sylhet', 'Beanibazar', 'বিয়ানীবাজার পৌরসভা', 'বাড়ি ৬, কলেজ রোড', 'বিয়ানীবাজার সরকারি কলেজের পাশে'],
        '01571946380' => ['Junaid Islam', 'en', 'Dhaka', null, 'Rampura', 'House 61, West Rampura', 'Behind Rampura TV centre'],
        '01692038475' => ['আরিফা ইয়াসমিন', 'bn', 'Rajshahi', 'Godagari', 'গোদাগাড়ী', 'বাড়ি ১২, হাসপাতাল রোড', 'গোদাগাড়ী উপজেলা হাসপাতালের সামনে'],
        '01318573042' => ['Saiful Islam', 'en', 'Khulna', 'Dumuria', 'Dumuria Bazar', 'Islam Store, Main Road', 'Opposite Dumuria upazila parishad'],
        '01827450396' => ['ফাতেমা তুজ জোহরা', 'bn', 'Dhaka', null, 'লালবাগ', 'বাড়ি ৪৪, লালবাগ রোড', 'লালবাগ কেল্লার পূর্ব গেটের কাছে'],
        '01714073658' => ['Shamim Reza', 'en', 'Dhaka', null, 'Moghbazar', 'House 7, New Eskaton Road', 'Near Moghbazar flyover ramp'],
        '01938264507' => ['লাইলা আরজুমান্দ', 'bn', 'Narayanganj', 'Rupganj', 'কাঞ্চন', 'বাড়ি ৩, কাঞ্চন বাজার', 'কাঞ্চন ব্রিজের পশ্চিম পাশে'],
        '01556103927' => ['Nayeem Uddin', 'en', 'Gazipur', 'Sreepur', 'Mawna', 'House 15, Mawna Chowrasta', 'Beside Mawna bus stand'],
        '01673048152' => ['শিরিন আক্তার', 'bn', 'Comilla', 'Chandina', 'চান্দিনা বাজার', 'বাড়ি ২৮, পুরাতন বাজার', 'চান্দিনা পাইলট স্কুলের পাশে'],
        '01793615208' => ['Ashraful Alam', 'en', 'Mymensingh', 'Bhaluka', 'Bhaluka Sadar', 'Alam Bhaban, Mymensingh Road', 'Near Bhaluka bus stand mosque'],
        '01917382650' => ['সুরাইয়া পারভীন', 'bn', 'Barisal', 'Bakerganj', 'বাকেরগঞ্জ', 'বাড়ি ৫, থানা রোড', 'বাকেরগঞ্জ থানার উল্টো দিকে'],
        '01405726391' => ['Kawsar Ahmed', 'en', 'Rangpur', 'Mithapukur', 'Mithapukur Bazar', 'House 2, College Road', 'Behind Mithapukur degree college'],
    ];

    /**
     * Phones that only ever have a cart (abandoned-cart recovery) — never an order before
     * the cart, so the recovery is not skipped as "already ordered". [name, consent].
     *
     * @var array<string, array{string, bool}>
     */
    public const array CART_PHONES = [
        '01762930415' => ['Rumana Hoque', true],
        '01883150624' => ['মাসুদ রানা', true],
        '01535290718' => ['Tasnia Rahman', false],
    ];

    /** Couriers named on a manually dispatched parcel (booked on the courier's portal, or our own rider). */
    private const array MANUAL_COURIERS = ['Steadfast (portal)', 'Steadfast (portal)', 'Steadfast (portal)', 'নিজস্ব রাইডার'];

    /**
     * [text, customer visible]. Internal notes are what staff keep for each other.
     *
     * @var list<array{string, bool}>
     */
    private const array NOTES = [
        ['Customer asked for delivery after 5 pm — office hours.', false],
        ['বিকেল ৫টার পর ডেলিভারি দিতে বলেছেন।', false],
        ['Gift wrap requested — birthday on Friday.', false],
        ['গিফট র‍্যাপ করে দিতে হবে, জন্মদিনের উপহার।', false],
        ['Called to confirm the address; landmark corrected.', false],
        ['ফোনে ঠিকানা নিশ্চিত করা হয়েছে।', false],
        ['Your parcel is packed and will be handed to the courier tomorrow.', true],
        ['আপনার পার্সেল আগামীকাল কুরিয়ারে হস্তান্তর করা হবে।', true],
        ['Repeat customer — keep the free toy sticker pack in the box.', false],
        ['Leave with the building guard if nobody answers.', false],
    ];

    /** @var array<string, string> cancellation reason → note shown on the timeline */
    private const array CANCEL_NOTES = [
        'customer_request' => 'গ্রাহক অর্ডারটি বাতিল করতে বলেছেন।',
        'customer_unreachable' => 'Called three times over two days, no answer.',
        'duplicate_order' => 'Placed twice by mistake; the other order is kept.',
        'address_unserviceable' => 'Customer moved; new address outside our delivery area for now.',
        'other' => 'Customer found the same toy in a local shop.',
    ];

    private Randomizer $rng;

    private CarbonImmutable $now;

    private int $history;

    /** @var list<array{CarbonImmutable, int, Closure(): void}> */
    private array $steps = [];

    private int $seq = 0;

    /** @var array<int, array{price: Money, product: string, published: CarbonImmutable}> variant id → price, product slug, when it went on sale */
    private array $variants = [];

    /** @var array<string, array{name: string, lang: string, district: string, upazila: string|null, area: string, line: string, landmark: string}> local phone → buyer */
    private array $buyers = [];

    /** @var array{operator: User, manager: User, accountant: User} */
    private array $staff;

    private NullCourierGateway $courier;

    private int $settlementNo = 0;

    private bool $shortLineDone = false;

    /** @var list<string> cart tokens of the abandoned carts, in CART_PHONES order */
    private array $abandoned = [];

    public function run(): void
    {
        if (Order::query()->whereIn('shipping_phone', self::allPhones())->exists()) {
            $this->command?->info('Demo orders already seeded — skipped.');

            return;
        }

        DemoOrderPlaces::flush();
        $this->now = CarbonImmutable::now('Asia/Dhaka')->subMinutes(2)->startOfMinute();
        $this->rng = new Randomizer(new Mt19937(20261113));
        $this->steps = [];
        $this->loadVariants();
        // Nothing can be sold before it is published (SellableVariants): the history starts
        // the day after the first demo product went live, however long demo.history_days is.
        $firstSale = min(array_map(static fn (array $v): int => $v['published']->getTimestamp(), $this->variants));
        $window = (int) floor(($this->now->getTimestamp() - $firstSale) / 86400);
        $this->history = max(7, min((int) config('demo.history_days', 60), $window));
        $this->loadBuyers();
        $this->staff = self::staff();

        $registry = app(CourierRegistry::class);
        $this->courier = new NullCourierGateway(self::COURIER);
        $registry->register(self::COURIER, $this->courier);

        try {
            DemoOrderSandbox::run(function (): void {
                $this->planOrders();
                $this->planSettlements();
                $this->planCarts();

                usort($this->steps, static fn (array $a, array $b): int => [$a[0]->getTimestamp(), $a[1]] <=> [$b[0]->getTimestamp(), $b[1]]);
                foreach ($this->steps as [$at, , $step]) {
                    if ($at->greaterThan($this->now)) {
                        continue;
                    }
                    DemoOrderSandbox::at($at);
                    $step();
                }

                // Projections, rebuilt once from the whole history (the listeners kept them
                // current per order; this makes the end state independent of event order).
                DemoOrderSandbox::at(null);
                app(RebuildCustomerAggregates::class)->handle();
                foreach (Order::query()->distinct()->orderBy('shipping_phone')->pluck('shipping_phone') as $phone) {
                    app(RefreshRiskProfile::class)->handle((string) $phone);
                }
            });
        } finally {
            /** @var class-string<\App\Domain\Fulfilment\Contracts\CourierGateway>|null $driver */
            $driver = config('couriers.drivers.'.self::COURIER);
            if (is_string($driver)) {
                $registry->register(self::COURIER, $driver);
            }
        }
    }

    /** @return list<string> every demo phone in E.164 (the idempotency marker) */
    public static function allPhones(): array
    {
        $local = [...DemoCustomersSeeder::PHONES, ...array_keys(self::GUESTS), ...array_keys(self::CART_PHONES)];

        return array_map(static fn (string $p): string => PhoneNumber::parse($p)->e164(), $local);
    }

    // ── Planning ────────────────────────────────────────────────────────────────────────

    private function planOrders(): void
    {
        $guests = array_keys(self::GUESTS);
        $reserved = array_slice($guests, 0, 7);
        $pool = [...DemoCustomersSeeder::PHONES, ...DemoCustomersSeeder::PHONES, ...array_slice($guests, 7)];

        // Scenarios every admin screen needs, at fixed ages (hours ago, or days ago at an hour).
        $scripted = [
            // Risk queue: large first orders from unknown phones, still waiting for a call.
            [$this->hoursAgo(4), $reserved[0], 'web', 'held', 'big', null],
            [$this->hoursAgo(19), $reserved[1], 'web', 'held', 'big', null],
            [$this->hoursAgo(30), $reserved[2], 'facebook', 'held', 'big', null],
            // Rejected after calls; verified and delivered.
            [$this->daysAgo(6, 21), $reserved[3], 'web', 'held_rejected', 'big', null],
            [$this->daysAgo(15, 22), $reserved[4], 'web', 'held_rejected', 'big', null],
            [$this->daysAgo(9, 11), $reserved[5], 'web', 'delivered', 'big', null],
            [$this->daysAgo(20, 16), $reserved[6], 'whatsapp', 'delivered', 'big', null],
            // The early pipeline, safely in the past whatever the time of day.
            [$this->hoursAgo(2), DemoCustomersSeeder::PHONES[4], 'web', 'pending', 'small', null],
            [$this->hoursAgo(6), DemoCustomersSeeder::PHONES[11], 'phone', 'pending', 'small', null],
            [$this->hoursAgo(3), DemoCustomersSeeder::PHONES[21], 'web', 'edited_pending', 'small', null],
            [$this->hoursAgo(22), DemoCustomersSeeder::PHONES[6], 'web', 'confirmed', 'small', null],
            [$this->hoursAgo(28), DemoCustomersSeeder::PHONES[14], 'facebook', 'processing', 'small', null],
            [$this->hoursAgo(56), DemoCustomersSeeder::PHONES[1], 'web', 'packed', 'small', null],
            [$this->hoursAgo(80), DemoCustomersSeeder::PHONES[17], 'web', 'shipped', 'normal', null],
            [$this->hoursAgo(50), DemoCustomersSeeder::PHONES[24], 'phone', 'cancelled', 'small', null],
            // Edited before packing, then delivered.
            [$this->daysAgo(7, 13), DemoCustomersSeeder::PHONES[9], 'phone', 'edited_delivered', 'small', null],
            // Returns and refunds.
            [$this->daysAgo(25, 12), DemoCustomersSeeder::PHONES[3], 'web', 'return_paid', 'normal', null],
            [$this->daysAgo(18, 20), DemoCustomersSeeder::PHONES[5], 'web', 'return_full', 'small', null],
            [$this->daysAgo(22, 18), DemoCustomersSeeder::PHONES[8], 'facebook', 'return_damaged', 'normal', null],
            [$this->daysAgo(30, 10), DemoCustomersSeeder::PHONES[13], 'web', 'return_rejected', 'small', null],
            [$this->daysAgo(27, 15), DemoCustomersSeeder::PHONES[19], 'web', 'refund_rejected', 'small', null],
            [$this->daysAgo(12, 17), DemoCustomersSeeder::PHONES[22], 'web', 'return_open', 'small', null],
            [$this->daysAgo(13, 11), DemoCustomersSeeder::PHONES[25], 'web', 'return_received', 'small', null],
            // Refused at the door: a courier return.
            [$this->daysAgo(12, 14), DemoCustomersSeeder::PHONES[16], 'web', 'courier_return', 'small', null],
            [$this->daysAgo(33, 19), DemoCustomersSeeder::PHONES[27], 'facebook', 'courier_return', 'normal', null],
            // Completed long ago.
            [$this->daysAgo(40, 12), DemoCustomersSeeder::PHONES[0], 'web', 'completed', 'normal', null],
        ];
        foreach ($scripted as [$at, $who, $channel, $target, $size, $coupon]) {
            $via = in_array($target, ['courier_return'], true) ? 'courier' : 'manual';
            $this->schedule($at, ['who' => $who, 'channel' => $channel, 'target' => $target, 'size' => $size, 'via' => $via, 'coupon' => $coupon, 'utm' => null, 'note' => null]);
        }

        // The everyday flow: denser towards today, a few busy days.
        $weights = [];
        for ($d = 0; $d < $this->history; $d++) {
            $weights[$d] = (1.0 + 1.5 * ($this->history - $d) / $this->history) * (self::BUSY_DAYS[$d] ?? 1.0);
        }
        // The volume follows demo.history_days even when the window is shorter (see run()).
        $total = (int) round(max($this->history, (int) config('demo.history_days', 60)) * self::ORDERS_PER_DAY) - count($scripted);
        $scale = $total / array_sum($weights);
        $firstOrderOf = [];

        for ($d = $this->history - 1; $d >= 0; $d--) {
            $expected = $weights[$d] * $scale;
            $count = (int) floor($expected) + ($this->rng->getFloat(0, 1) < $expected - floor($expected) ? 1 : 0);
            for ($i = 0; $i < $count; $i++) {
                $at = $this->daysAgo($d, $this->hour());
                if ($at->greaterThan($this->now->subMinutes(20))) {
                    continue;
                }
                $who = $pool[$this->rng->getInt(0, count($pool) - 1)];
                $channel = $this->pick(['web' => 60, 'phone' => 15, 'facebook' => 15, 'whatsapp' => 10]);
                $target = $this->targetFor($at);
                $coupon = null;
                if (! isset($firstOrderOf[$who])) {
                    $firstOrderOf[$who] = true;
                    $coupon = in_array($who, DemoCustomersSeeder::PHONES, true) && $this->chance(40) ? 'WELCOME100' : null;
                } elseif ($this->chance(15)) {
                    $coupon = 'TOY10';
                }
                $daysAgo = (int) $at->diffInDays($this->now);
                $this->schedule($at, [
                    'who' => $who,
                    'channel' => $channel,
                    'target' => $target,
                    'size' => $this->pick(['small' => 45, 'normal' => 45, 'big' => 10]),
                    'via' => in_array($target, ['delivered', 'completed'], true) && $daysAgo >= 6 && $this->chance(45) ? 'courier' : 'manual',
                    'coupon' => $coupon,
                    'utm' => $channel === 'web' && $this->chance(30) ? ($daysAgo <= 4 ? '11-11-mega-sale' : 'winter-toys') : null,
                    'note' => $this->chance(15) ? (string) $this->rng->getInt(0, count(self::NOTES) - 1) : null,
                ]);
            }
        }
    }

    /** What an everyday order placed at this moment has become by now. */
    private function targetFor(CarbonImmutable $at): string
    {
        $hours = $at->diffInHours($this->now);

        return match (true) {
            $hours < 24 => $this->pick(['pending' => 45, 'confirmed' => 25, 'processing' => 10, 'packed' => 8, 'cancelled' => 12]),
            $hours < 72 => $this->pick(['confirmed' => 8, 'packed' => 20, 'shipped' => 50, 'delivered' => 12, 'cancelled' => 10]),
            $hours < 120 => $this->pick(['shipped' => 35, 'delivered' => 55, 'cancelled' => 10]),
            $hours < 360 => $this->pick(['delivered' => 82, 'cancelled' => 9, 'courier_return' => 3, 'return_paid' => 6]),
            default => $this->pick(['delivered' => 58, 'completed' => 24, 'cancelled' => 9, 'courier_return' => 3, 'return_paid' => 6]),
        };
    }

    /**
     * Turn one plan into timed steps.
     *
     * @param  array{who: string, channel: string, target: string, size: string, via: string, coupon: string|null, utm: string|null, note: string|null}  $plan
     */
    private function schedule(CarbonImmutable $placedAt, array $plan): void
    {
        $t = new DemoOrderTrack($plan);
        $target = $plan['target'];
        $via = $target === 'courier_return' ? 'courier' : $plan['via'];
        if (str_starts_with($target, 'return_') || $target === 'refund_rejected') {
            $via = 'manual'; // customer returns are taken back by hand, never through a courier settlement
        }

        $this->step($placedAt, fn () => $this->place($t));
        if ($plan['note'] !== null) {
            [$text, $visible] = self::NOTES[(int) $plan['note']];
            $this->step($placedAt->addMinutes($this->rng->getInt(20, 90)), fn () => $this->note($t, $text, $visible));
        }

        if ($target === 'held') {
            $this->step($this->business($placedAt->addMinutes(50)), fn () => $this->logCall($t, CallOutcome::NoAnswer, null));

            return;
        }
        if ($target === 'held_rejected') {
            $first = $this->business($placedAt->addMinutes(45));
            $this->step($first, fn () => $this->logCall($t, CallOutcome::NoAnswer, null));
            $this->step($first->addHours(3), fn () => $this->logCall($t, CallOutcome::SwitchedOff, 'ফোন বন্ধ।'));
            $this->step($first->addDay(), fn () => $this->logCall($t, CallOutcome::WrongNumber, 'The person who answered never ordered anything.'));
            $this->step($first->addDay()->addMinutes(10), fn () => $this->rejectHeld($t));

            return;
        }
        if ($target === 'pending') {
            return;
        }

        $edited = str_starts_with($target, 'edited_');
        if ($edited) {
            $this->step($placedAt->addMinutes(35), fn () => $this->edit($t));
            $target = substr($target, strlen('edited_'));
            if ($target === 'pending') {
                return;
            }
        }

        if ($target === 'cancelled') {
            $reason = $this->pick(['customer_request' => 40, 'customer_unreachable' => 25, 'duplicate_order' => 15, 'address_unserviceable' => 8, 'other' => 12]);
            $confirmFirst = $this->chance(40);
            $at = $this->business($placedAt->addMinutes($this->rng->getInt(40, 300)));
            if ($confirmFirst) {
                $this->step($at, fn () => $this->confirm($t));
                $at = $this->business($at->addHours($this->rng->getInt(2, 20)));
            }
            $this->step($at, fn () => $this->cancel($t, CancellationReason::from($reason)));

            return;
        }

        $confirmAt = $this->business($placedAt->addMinutes($this->rng->getInt(45, 240)));
        $this->step($confirmAt, fn () => $this->confirm($t));
        if ($target === 'confirmed') {
            return;
        }
        if ($target === 'processing') {
            $this->step($confirmAt->addMinutes(90), fn () => $this->transition($t, OrderStatus::Processing));

            return;
        }

        $packAt = $this->business($confirmAt->addHours($this->rng->getInt(12, 18)));
        $this->step($packAt, fn () => $this->transition($t, OrderStatus::ReadyToShip));
        if ($target === 'packed') {
            return;
        }

        $shipAt = $this->business($packAt->addHours($this->rng->getInt(3, 6)));
        $inDhaka = self::isDhaka($this->buyers[$plan['who']]['district']);
        $deliverAt = $this->business($shipAt->addHours($inDhaka ? $this->rng->getInt(20, 30) : $this->rng->getInt(44, 72)));

        if ($via === 'courier') {
            $this->step($shipAt, fn () => $this->book($t));
            $this->step($shipAt->addHours(3), fn () => $this->courierStatus($t, ShipmentStatus::PickedUp, 'picked_up'));
            $this->step($shipAt->addHours(9), fn () => $this->courierStatus($t, ShipmentStatus::InTransit, 'in_transit'));
            $this->step($deliverAt->subHours(4), fn () => $this->courierStatus($t, ShipmentStatus::OutForDelivery, 'out_for_delivery'));
            if ($target === 'courier_return') {
                $this->step($deliverAt, fn () => $this->courierStatus($t, ShipmentStatus::OnHold, 'hold'));
                $this->step($this->business($deliverAt->addDays(2)), fn () => $this->courierStatus($t, ShipmentStatus::Returned, 'returned'));

                return;
            }
            $this->step($deliverAt, fn () => $this->courierStatus($t, ShipmentStatus::Delivered, 'delivered'));
        } else {
            $this->step($shipAt, fn () => $this->shipManually($t));
            if ($target === 'shipped') {
                return;
            }
            $this->step($deliverAt, fn () => $this->deliverManually($t));
            // The portal courier pays out by hand a few days later (not for the newest week).
            if ($this->chance(75)) {
                $this->step($this->business($deliverAt->addDays($this->rng->getInt(4, 7))->setTime(16, 0)), fn () => $this->codReceived($t));
            }
        }

        if ($target === 'completed') {
            $this->step($deliverAt->addDays(8), fn () => $this->transition($t, OrderStatus::Completed));

            return;
        }

        $requestAt = $this->business($deliverAt->addDays(2));
        $receiveAt = $this->business($requestAt->addDays(2));
        $inspectAt = $receiveAt->addHours(2);
        $approveAt = $inspectAt->addHour();
        $payAt = $approveAt->addHours(3);
        match ($target) {
            'return_paid' => $this->returnFlow($t, $requestAt, $receiveAt, $inspectAt, $approveAt, $payAt, ReturnReason::Defective, partial: true, damaged: 0, shipping: false, end: 'paid'),
            'return_full' => $this->returnFlow($t, $requestAt, $receiveAt, $inspectAt, $approveAt, $payAt, ReturnReason::WrongItem, partial: false, damaged: 0, shipping: true, end: 'paid'),
            'return_damaged' => $this->returnFlow($t, $requestAt, $receiveAt, $inspectAt, $approveAt, $payAt, ReturnReason::DamagedInTransit, partial: true, damaged: 1, shipping: false, end: 'paid'),
            'refund_rejected' => $this->returnFlow($t, $requestAt, $receiveAt, $inspectAt, $approveAt, $payAt, ReturnReason::ChangedMind, partial: true, damaged: 0, shipping: false, end: 'refund_rejected'),
            'return_rejected' => $this->returnFlow($t, $requestAt, $receiveAt, $inspectAt, $approveAt, $payAt, ReturnReason::NotAsDescribed, partial: true, damaged: 0, shipping: false, end: 'return_rejected'),
            'return_open' => $this->returnFlow($t, $requestAt, $receiveAt, $inspectAt, $approveAt, $payAt, ReturnReason::MissingParts, partial: true, damaged: 0, shipping: false, end: 'requested'),
            'return_received' => $this->returnFlow($t, $requestAt, $receiveAt, $inspectAt, $approveAt, $payAt, ReturnReason::Defective, partial: true, damaged: 0, shipping: false, end: 'received'),
            default => null,
        };
    }

    private function returnFlow(
        DemoOrderTrack $t,
        CarbonImmutable $requestAt,
        CarbonImmutable $receiveAt,
        CarbonImmutable $inspectAt,
        CarbonImmutable $approveAt,
        CarbonImmutable $payAt,
        ReturnReason $reason,
        bool $partial,
        int $damaged,
        bool $shipping,
        string $end,
    ): void {
        $this->step($requestAt, fn () => $this->requestReturn($t, $reason, $partial, $shipping));
        if ($end === 'requested') {
            return;
        }
        if ($end === 'return_rejected') {
            $this->step($receiveAt, fn () => $this->onReturn($t, static fn (OrderReturn $r, int $user) => app(RejectReturn::class)->handle($r, 'Outside the 7-day return window, and the box was opened and used.')));

            return;
        }
        $this->step($receiveAt, fn () => $this->onReturn($t, static fn (OrderReturn $r, int $user) => app(ReceiveReturn::class)->handle($r)));
        if ($end === 'received') {
            return;
        }
        $this->step($inspectAt, fn () => $this->inspect($t, $damaged));
        if ($end === 'refund_rejected') {
            $this->step($approveAt, fn () => $this->onRefund($t, static fn (Refund $f, User $u) => app(RejectRefund::class)->handle($f, 'Change of mind on an opened toy — store credit offered instead, customer agreed.', $u->id)));

            return;
        }
        $this->step($approveAt, fn () => $this->onRefund($t, static fn (Refund $f, User $u) => app(ApproveRefund::class)->handle($f, $u->id, $u->can(Permission::RefundsApprove->value))));
        $this->step($payAt, fn () => $this->onRefund($t, static fn (Refund $f, User $u) => app(PayRefund::class)->handle($f, PaymentMethod::Bkash, 'BK'.str_pad((string) ($f->id * 7919 % 1000000), 6, '0', STR_PAD_LEFT).'X'.$f->id, $u->id)));
    }

    /** Weekly courier remittances for the courier-delivered parcels; the newest stays unconfirmed. */
    private function planSettlements(): void
    {
        $days = array_values(array_filter([40, 33, 26, 19, 12, 5], fn (int $d): bool => $d < $this->history - 3));
        foreach ($days as $i => $d) {
            $at = $this->daysAgo($d, 15);
            $holder = new class
            {
                public ?int $id = null;
            };
            $this->step($at, function () use ($holder): void {
                $holder->id = $this->importSettlement();
            });
            if ($i < count($days) - 1) {
                $this->step($at->addDay()->setTime(11, 30), function () use ($holder): void {
                    if ($holder->id !== null) {
                        $this->confirmSettlement($holder->id);
                    }
                });
            }
        }
    }

    /** Abandoned carts (yesterday), one recovered by its order, and two carts open right now. */
    private function planCarts(): void
    {
        $yesterday = $this->now->subDay();
        $i = 0;
        foreach (self::CART_PHONES as $phone => [$name, $consent]) {
            $this->step($yesterday->setTime(11, 10 + 25 * $i), fn () => $this->abandonCart($phone, $consent));
            $i++;
        }
        $this->step($yesterday->setTime(15, 30), function (): void {
            app(DetectAbandonedCarts::class)->handle(now());
        });
        $first = array_key_first(self::CART_PHONES);
        $recovered = new DemoOrderTrack(['who' => $first, 'channel' => 'web', 'target' => 'confirmed', 'size' => 'small', 'via' => 'manual', 'coupon' => null, 'utm' => null, 'note' => null]);
        $this->step($yesterday->setTime(20, 5), fn () => $this->checkoutAbandoned($recovered));
        $this->step($this->business($yesterday->setTime(20, 5)->addHours(13)), fn () => $this->confirm($recovered));

        $this->step($this->now->subMinutes(42), fn () => $this->openCart(null));
        $this->step($this->now->subMinutes(15), fn () => $this->openCart(DemoCustomersSeeder::PHONES[29]));
    }

    // ── Steps ───────────────────────────────────────────────────────────────────────────

    private function place(DemoOrderTrack $t): void
    {
        $plan = $t->plan;
        $lines = $this->pickLines($plan['size']);
        if ($lines === []) {
            return;
        }

        $coupon = $plan['coupon'] !== null && Coupon::findByCode($plan['coupon']) !== null ? $plan['coupon'] : null;
        $order = $this->tryPlace($plan, $lines, $coupon);
        if ($order === null && $coupon !== null) {
            $order = $this->tryPlace($plan, $lines, null);
        }
        if ($order === null) {
            $this->command?->warn("Demo order for {$plan['who']} could not be placed — skipped.");

            return;
        }
        $t->order = $order;
    }

    /**
     * @param  array{who: string, channel: string, target: string, size: string, via: string, coupon: string|null, utm: string|null, note: string|null}  $plan
     * @param  array<int, int>  $lines
     */
    private function tryPlace(array $plan, array $lines, ?string $coupon): ?Order
    {
        $buyer = $this->buyers[$plan['who']];
        $phone = PhoneNumber::parse($plan['who']);
        $district = DemoOrderPlaces::district($buyer['district']);
        $upazila = DemoOrderPlaces::upazila($buyer['district'], $buyer['upazila']);

        try {
            if ($plan['channel'] === 'web') {
                $token = $this->cartWith($lines);
                if ($coupon !== null) {
                    try {
                        app(ApplyCoupon::class)->handle($token, $coupon);
                    } catch (ValidationException) {
                        return null;
                    }
                }

                return app(PlaceOrder::class)->handle($this->checkout($token, $plan['who'], $plan['utm']));
            }

            $breakdown = app(OrderQuotes::class)->forNewOrder($lines, $coupon, $district, $upazila, $phone);
            if (OrderQuotes::blockers($breakdown, $upazila) !== []) {
                return null;
            }

            $this->actAs($this->staff['operator']);

            return app(PlaceManualOrder::class)->handle(new ManualOrderData(
                lines: $lines,
                name: $buyer['name'],
                phone: $phone,
                district: $district,
                upazila: $upazila,
                area: $buyer['area'],
                addressLine: $buyer['line'],
                landmark: $buyer['landmark'],
                expectedTotal: $breakdown->grandTotal,
                source: OrderSource::from($plan['channel']),
                couponCode: $coupon,
                internalNote: match ($plan['channel']) {
                    'facebook' => 'Ordered via Facebook page inbox.',
                    'whatsapp' => 'হোয়াটসঅ্যাপে অর্ডার করেছেন।',
                    default => 'Phoned the hotline.',
                },
                userId: $this->staff['operator']->id,
                locale: $buyer['lang'],
            ));
        } catch (CheckoutNotReady|PriceChanged|InsufficientStock|CouponRejected|PromotionRejected|CartNotFound) {
            return null;
        }
    }

    /** @param  array<int, int>  $lines */
    private function cartWith(array $lines): string
    {
        $token = null;
        foreach ($lines as $variantId => $quantity) {
            $token = app(AddCartLine::class)->handle($token, $variantId, $quantity)->cart->token;
        }

        return (string) $token;
    }

    private function checkout(string $token, string $who, ?string $campaign): CheckoutData
    {
        $buyer = $this->buyers[$who];
        $phone = PhoneNumber::parse($who);
        $district = DemoOrderPlaces::district($buyer['district']);
        $upazila = DemoOrderPlaces::upazila($buyer['district'], $buyer['upazila']);
        $cart = Cart::query()->with('coupon')->where('token', $token)->firstOrFail();
        $customerId = Customer::query()->withTrashed()->where('phone', $phone->e164())->value('id');

        $total = app(PricingPipeline::class)->price(new PricingInput(
            lines: $cart->lines(),
            couponCode: $cart->coupon?->code,
            district: $district,
            upazila: $upazila,
            phone: $phone,
            customerId: is_int($customerId) ? $customerId : null,
        ))->grandTotal;

        return new CheckoutData(
            cartToken: $token,
            name: $buyer['name'],
            phone: $phone,
            district: $district,
            upazila: $upazila,
            area: $buyer['area'],
            addressLine: $buyer['line'],
            expectedTotal: $total,
            landmark: $buyer['landmark'],
            source: OrderSource::Web,
            ipAddress: '103.4.'.$this->rng->getInt(1, 254).'.'.$this->rng->getInt(1, 254),
            userAgent: 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36',
            utm: $campaign === null ? [] : ['source' => 'facebook', 'medium' => 'paid_social', 'campaign' => $campaign],
            locale: $buyer['lang'],
        );
    }

    private function confirm(DemoOrderTrack $t): void
    {
        $order = $t->fresh();
        if ($order === null) {
            return;
        }
        if ($order->status === OrderStatus::OnHold && $order->verification_status === VerificationStatus::Pending) {
            // Risk held it (rule 33b): a person calls, then verifies — which confirms it.
            if ($this->chance(35)) {
                $this->logCall($t, CallOutcome::NoAnswer, null);
            }
            $this->logCall($t, CallOutcome::Confirmed, 'নিশ্চিত করেছেন, ঠিকানা ঠিক আছে।');
            $this->actAs($this->staff['manager']);
            $t->order = app(VerifyHeldOrder::class)->handle($order, $this->staff['manager']->id, 'Verified by phone.');

            return;
        }
        if ($order->status === OrderStatus::Pending) {
            $this->transition($t, OrderStatus::Confirmed);
        }
    }

    private function transition(DemoOrderTrack $t, OrderStatus $to): void
    {
        $order = $t->fresh();
        if ($order === null || ! $order->status->canTransitionTo($to)) {
            return;
        }
        $this->actAs($this->staff['operator']);
        $t->order = app(TransitionOrder::class)->handle($order, $to, $this->staff['operator']->id);
    }

    private function cancel(DemoOrderTrack $t, CancellationReason $reason): void
    {
        $order = $t->fresh();
        if ($order === null || ! $order->status->isCancellable()) {
            return;
        }
        $note = self::CANCEL_NOTES[$reason->value] ?? null;
        if ($order->status === OrderStatus::OnHold && $order->verification_status === VerificationStatus::Pending) {
            $this->actAs($this->staff['manager']);
            $t->order = app(RejectHeldOrder::class)->handle($order, $reason, $note, $this->staff['manager']->id);

            return;
        }
        $this->actAs($this->staff['operator']);
        $t->order = app(CancelOrder::class)->handle($order, $reason, $note, $this->staff['operator']->id);
    }

    private function logCall(DemoOrderTrack $t, CallOutcome $outcome, ?string $note): void
    {
        $order = $t->fresh();
        if ($order === null || $order->verification_status !== VerificationStatus::Pending) {
            return;
        }
        $this->actAs($this->staff['operator']);
        app(LogVerificationCall::class)->handle($order->id, $order->shipping_phone, $outcome, $note, $this->staff['operator']->id);
    }

    private function rejectHeld(DemoOrderTrack $t): void
    {
        $order = $t->fresh();
        if ($order === null || $order->verification_status !== VerificationStatus::Pending) {
            return;
        }
        $this->actAs($this->staff['manager']);
        $t->order = app(RejectHeldOrder::class)->handle($order, CancellationReason::FakeOrder, 'Number does not belong to the buyer; nobody ordered.', $this->staff['manager']->id);
    }

    /** The customer phones back to add a toy (or one more of the first line). */
    private function edit(DemoOrderTrack $t): void
    {
        $order = $t->fresh()?->load('items');
        if ($order === null || ! in_array($order->status, [OrderStatus::Pending, OrderStatus::OnHold, OrderStatus::Confirmed, OrderStatus::Processing], true)) {
            return;
        }
        $lines = [];
        foreach ($order->items as $item) {
            if ($item->variant_id !== null && $item->quantity > 0) {
                $lines[$item->variant_id] = $item->quantity;
            }
        }
        $extra = $this->pickLines('small', array_keys($lines));
        if ($extra === []) {
            return;
        }
        $lines += $extra;
        ksort($lines);

        $district = DemoOrderPlaces::district((string) $order->shipping_district);
        $upazila = $order->shipping_upazila === null ? null : DemoOrderPlaces::upazila((string) $order->shipping_district, (string) $order->shipping_upazila);
        $total = app(OrderQuotes::class)->forEdit($order, $lines, $district, $upazila)->grandTotal;

        $this->actAs($this->staff['operator']);
        $t->order = app(EditOrder::class)->handle($order, new OrderEditData(
            lines: $lines,
            name: $order->shipping_name,
            phone: PhoneNumber::parse($order->shipping_phone),
            district: $district,
            upazila: $upazila,
            area: (string) $order->shipping_area,
            addressLine: (string) $order->shipping_address,
            landmark: (string) $order->shipping_landmark,
            expectedTotal: $total,
            note: 'Customer called to add one more toy.',
        ), $this->staff['operator']->id);
    }

    private function note(DemoOrderTrack $t, string $text, bool $visible): void
    {
        $order = $t->fresh();
        if ($order === null) {
            return;
        }
        $this->actAs($this->staff['operator']);
        app(AddOrderNote::class)->handle($order, $text, $visible, $this->staff['operator']->id);
    }

    private function shipManually(DemoOrderTrack $t): void
    {
        $order = $t->fresh();
        if ($order === null || ! $order->status->canTransitionTo(OrderStatus::Shipped)) {
            return;
        }
        $courier = self::MANUAL_COURIERS[$this->rng->getInt(0, count(self::MANUAL_COURIERS) - 1)];
        $reference = str_starts_with($courier, 'Steadfast') ? 'SF'.$this->rng->getInt(10000000, 99999999) : null;
        $this->actAs($this->staff['operator']);
        $t->order = app(MarkOrderShipped::class)->handle($order, $courier, $reference, $this->staff['operator']->id);
    }

    private function deliverManually(DemoOrderTrack $t): void
    {
        $order = $t->fresh();
        if ($order === null || $order->status !== OrderStatus::Shipped) {
            return;
        }
        $this->actAs($this->staff['operator']);
        $t->order = app(MarkOrderDelivered::class)->handle($order, $this->staff['operator']->id);
    }

    /** The portal courier's payout for a delivered order, recorded by the accountant. */
    private function codReceived(DemoOrderTrack $t): void
    {
        $order = $t->fresh();
        if ($order === null || ! in_array($order->status, [OrderStatus::Delivered, OrderStatus::Completed], true)) {
            return;
        }
        $due = app(Receivables::class)->forOrder($order->id);
        if (! $due->greaterThan(Money::zero())) {
            return;
        }
        $this->actAs($this->staff['accountant']);
        app(RecordCodReceived::class)->handle(
            $order,
            $due,
            CarbonImmutable::now('Asia/Dhaka')->startOfDay(),
            'SF-PAY-'.$order->id,
            'Steadfast portal payout, bKash merchant transfer.',
            $this->staff['accountant']->id,
        );
    }

    /** Book the parcel through the courier integration (the fixture driver, see the class docblock). */
    private function book(DemoOrderTrack $t): void
    {
        $order = $t->fresh();
        if ($order === null || ! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::ReadyToShip], true)) {
            return;
        }
        $this->actAs($this->staff['operator']);
        $shipment = app(RequestShipment::class)->handle($order->id, self::COURIER, userId: $this->staff['operator']->id, dispatch: false);

        $consignment = 'DEMO'.str_pad((string) (104000 + $shipment->id * 37), 7, '0', STR_PAD_LEFT);
        $charge = match (true) {
            $order->shipping_district === 'Dhaka' && $order->shipping_upazila === null => '70.00',
            self::isDhaka((string) $order->shipping_district) => '100.00',
            default => '130.00',
        };
        $this->courier->willBook(BookingResult::accepted($consignment, 'SFT'.substr($consignment, 4), Money::of($charge), 'in_review'));
        Bus::dispatchSync(new BookShipment($shipment->id, $this->staff['operator']->id));

        $t->shipmentId = $shipment->id;
        $t->consignment = $consignment;
        $t->order = $order->refresh();
    }

    private function courierStatus(DemoOrderTrack $t, ShipmentStatus $status, string $raw): void
    {
        if ($t->shipmentId === null || $t->consignment === null) {
            return;
        }
        $shipment = Shipment::query()->findOrFail($t->shipmentId);
        if (! $shipment->status->canTransitionTo($status)) {
            return;
        }
        $collected = $status === ShipmentStatus::Delivered ? $shipment->cod_amount : null;
        $this->courier->willReportStatus($t->consignment, new CourierStatusResult($status, $raw, CarbonImmutable::now(), $collected));
        app(RefreshShipmentStatus::class)->handle($shipment->id, 'poll', $t->consignment);
        $t->fresh();
    }

    private function requestReturn(DemoOrderTrack $t, ReturnReason $reason, bool $partial, bool $shipping): void
    {
        $order = $t->fresh()?->load('items');
        if ($order === null || $order->status !== OrderStatus::Delivered) {
            return;
        }
        $lines = [];
        foreach ($order->items as $item) {
            if ($item->quantity_shipped > 0) {
                $lines[$item->id] = $partial ? 1 : $item->quantity_shipped;
                if ($partial) {
                    break;
                }
            }
        }
        $this->actAs($this->staff['operator']);
        $return = app(RequestReturn::class)->handle(
            $order,
            $lines,
            $reason,
            match ($reason) {
                ReturnReason::Defective => 'The sound module stopped working on day two.',
                ReturnReason::WrongItem => 'ভুল রঙের সেট এসেছে।',
                ReturnReason::DamagedInTransit => 'Box crushed on arrival, one piece broken.',
                ReturnReason::MissingParts => 'দুটি অংশ বাক্সে ছিল না।',
                default => null,
            },
            $shipping,
            $this->staff['operator']->id,
        );
        $t->returnId = $return->id;
    }

    /** @param  Closure(OrderReturn, int): mixed  $action */
    private function onReturn(DemoOrderTrack $t, Closure $action): void
    {
        if ($t->returnId === null) {
            return;
        }
        $this->actAs($this->staff['operator']);
        $action(OrderReturn::query()->findOrFail($t->returnId), $this->staff['operator']->id);
    }

    private function inspect(DemoOrderTrack $t, int $damaged): void
    {
        if ($t->returnId === null) {
            return;
        }
        $return = OrderReturn::query()->with('items')->findOrFail($t->returnId);
        $inspection = [];
        foreach ($return->items as $line) {
            $bad = min($damaged, $line->quantity);
            $inspection[$line->order_item_id] = ['resellable' => $line->quantity - $bad, 'damaged' => $bad];
            $damaged -= $bad;
        }
        $this->actAs($this->staff['operator']);
        app(InspectReturn::class)->handle($return, $inspection, $this->staff['operator']->id, 'Checked at Mirpur HQ.');
    }

    /** @param  Closure(Refund, User): mixed  $action */
    private function onRefund(DemoOrderTrack $t, Closure $action): void
    {
        if ($t->returnId === null) {
            return;
        }
        $refund = OrderReturn::query()->findOrFail($t->returnId)->refund;
        if (! $refund instanceof Refund) {
            return;
        }
        $this->actAs($this->staff['manager']);
        $action($refund, $this->staff['manager']);
    }

    /** Import this week's remittance: every courier-final shipment not yet on a statement. */
    private function importSettlement(): ?int
    {
        $providerId = CourierProvider::query()->where('code', self::COURIER)->value('id');
        $settled = CourierSettlementItem::query()->whereNotNull('shipment_id')->pluck('shipment_id')->all();
        $shipments = Shipment::query()
            ->where('provider_id', $providerId)
            ->whereIn('status', [ShipmentStatus::Delivered->value, ShipmentStatus::Returned->value])
            ->where(fn ($q) => $q->where('delivered_at', '<=', now()->subDay())->orWhere('returned_at', '<=', now()->subDay()))
            ->whereNotIn('id', $settled ?: [0])
            ->orderBy('id')
            ->get();
        if ($shipments->isEmpty()) {
            return null;
        }

        $lines = [];
        $short = ! $this->shortLineDone && $shipments->count() >= 3;
        foreach ($shipments->values() as $i => $shipment) {
            $delivered = $shipment->status === ShipmentStatus::Delivered;
            $cod = $delivered ? ($shipment->cod_collected ?? $shipment->cod_amount) : Money::zero();
            if ($short && $i === $shipments->count() - 1) {
                $cod = $cod->minus(Money::of('100.00')); // the courier paid ৳100 short on one parcel
            }
            $lines[] = new SettlementLine(
                lineNo: $i + 1,
                consignmentId: (string) $shipment->consignment_id,
                codAmount: $cod,
                charges: $shipment->charge ?? Money::zero(),
                codFee: $delivered ? ($shipment->cod_fee ?? $shipment->cod_amount->percentage(1)) : Money::zero(),
                returnCharge: $delivered ? Money::zero() : ($shipment->return_charge ?? Money::zero()),
            );
        }
        $this->shortLineDone = $this->shortLineDone || $short;
        $this->settlementNo++;

        $this->actAs($this->staff['accountant']);
        $settlement = app(ImportCourierSettlement::class)->handle(
            self::COURIER,
            'SFC-DEMO-'.CarbonImmutable::now('Asia/Dhaka')->format('ymd').'-'.$this->settlementNo,
            CarbonImmutable::now('Asia/Dhaka')->startOfDay(),
            $lines,
            'manual',
            note: 'Weekly Steadfast COD payout (bank transfer).',
            userId: $this->staff['accountant']->id,
        );

        return $settlement->id;
    }

    private function confirmSettlement(int $id): void
    {
        $settlement = \App\Domain\Finance\Models\CourierSettlement::query()->with('items')->findOrFail($id);
        $this->actAs($this->staff['accountant']);
        foreach ($settlement->items as $item) {
            if ($item->variance_type === SettlementVariance::Short) {
                app(ReviewSettlementLine::class)->handle($settlement, $item, SettlementReview::Accepted, 'Courier deducted ৳100 for a damaged box; dispute raised with Steadfast.', $this->staff['accountant']->id);
            } elseif ($item->variance_type !== SettlementVariance::None) {
                app(ReviewSettlementLine::class)->handle($settlement, $item, SettlementReview::Excluded, 'Not ours to book — raised with the courier.', $this->staff['accountant']->id);
            }
        }
        app(ConfirmCourierSettlement::class)->handle($settlement->refresh(), $this->staff['accountant']->id);
    }

    private function abandonCart(string $phone, bool $consent): void
    {
        $lines = $this->pickLines('small');
        if ($lines === []) {
            return;
        }
        $token = $this->cartWith($lines);
        app(SetCartContact::class)->handle($token, PhoneNumber::parse($phone), $consent, 'bn');
        $this->abandoned[] = $token;
    }

    /** The first abandoned cart's shopper comes back after the reminder and orders it. */
    private function checkoutAbandoned(DemoOrderTrack $t): void
    {
        $token = $this->abandoned[0] ?? null;
        if ($token === null) {
            return;
        }
        try {
            $t->order = app(PlaceOrder::class)->handle($this->checkout($token, $t->plan['who'], null));
        } catch (CheckoutNotReady|PriceChanged|InsufficientStock|CouponRejected|PromotionRejected|CartNotFound) {
            $this->command?->warn('Demo recovered-cart order could not be placed — skipped.');
        }
    }

    private function openCart(?string $phone): void
    {
        $lines = $this->pickLines('normal');
        if ($lines === []) {
            return;
        }
        $token = $this->cartWith($lines);
        if ($phone !== null) {
            app(SetCartContact::class)->handle($token, PhoneNumber::parse($phone), true, 'en');
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────────

    /** @param  Closure(): void  $step */
    private function step(CarbonImmutable $at, Closure $step): void
    {
        $this->steps[] = [$at, $this->seq++, $step];
    }

    private function actAs(User $user): void
    {
        Auth::guard('web')->setUser($user);
    }

    /**
     * Lines for a new order from variants with headroom: `small` one cheap toy, `normal` one
     * to three toys, `big` one toy of ৳3,000+ (a first order that size is held for a call).
     *
     * @param  list<int>  $exclude
     * @return array<int, int> variant id → quantity
     */
    private function pickLines(string $size, array $exclude = []): array
    {
        $free = $this->freeStock();
        $now = CarbonImmutable::now();
        $eligible = array_filter(
            $this->variants,
            static fn (array $v, int $id): bool => ($free[$id] ?? 0) - 1 >= self::STOCK_FLOOR
                && ! $v['published']->greaterThan($now)
                && ! in_array($id, $exclude, true),
            ARRAY_FILTER_USE_BOTH,
        );
        $by = static fn (Closure $keep): array => array_filter($eligible, static fn (array $v): bool => $keep($v['price']->toPoisha()));

        $candidates = match ($size) {
            'small' => $by(static fn (int $p): bool => $p < 200000),
            'big' => $by(static fn (int $p): bool => $p >= 300000),
            default => $eligible,
        };
        if ($candidates === []) {
            $candidates = $eligible;
        }
        if ($candidates === []) {
            return [];
        }

        $count = $size === 'normal' ? (int) $this->pick(['1' => 55, '2' => 35, '3' => 10]) : 1;
        $lines = [];
        for ($i = 0; $i < $count && $candidates !== []; $i++) {
            $weights = [];
            foreach ($candidates as $id => $v) {
                $p = $v['price']->toPoisha();
                $weights[(string) $id] = $p < 150000 ? 5 : ($p < 300000 ? 3 : ($p < 500000 ? 2 : 1));
            }
            $id = (int) $this->pick($weights);
            $quantity = $size !== 'big' && ($free[$id] ?? 0) - 2 >= self::STOCK_FLOOR && $this->chance(18) ? 2 : 1;
            $lines[$id] = $quantity;
            unset($candidates[$id]);
        }
        ksort($lines);

        return $lines;
    }

    /** @return array<int, int> variant id → units free (on hand − reserved) at the default location */
    private function freeStock(): array
    {
        return StockLevel::query()
            ->where('location_id', StockLocation::defaultId())
            ->whereIn('variant_id', array_keys($this->variants))
            ->get(['variant_id', 'on_hand', 'reserved'])
            ->mapWithKeys(static fn (StockLevel $l): array => [(int) $l->variant_id => (int) $l->on_hand - (int) $l->reserved])
            ->all();
    }

    private function loadVariants(): void
    {
        $products = Product::query()->whereIn('slug', array_keys(DemoCatalogSeeder::PRODUCTS))->whereNotNull('published_at')->get()->keyBy('id');
        if ($products->isEmpty()) {
            throw new RuntimeException('Demo orders need DemoCatalogSeeder (and DemoInventorySeeder) first.');
        }
        $this->variants = [];
        foreach (ProductVariant::query()->whereIn('product_id', $products->keys()->all())->orderBy('id')->get() as $variant) {
            $product = $products[$variant->product_id];
            $this->variants[(int) $variant->id] = [
                'price' => $variant->price,
                'product' => (string) $product->slug,
                'published' => CarbonImmutable::parse($product->published_at),
            ];
        }
    }

    private function loadBuyers(): void
    {
        $this->buyers = [];
        foreach (DemoCustomersSeeder::CUSTOMERS as $phone => $row) {
            $address = $row['addresses'][($row['default2'] ?? false) ? 1 : 0];
            $this->buyers[$phone] = ['name' => $row['name'], 'lang' => $row['lang'], 'district' => $address[2], 'upazila' => $address[3], 'area' => $address[4], 'line' => $address[5], 'landmark' => $address[6]];
        }
        foreach (self::GUESTS as $phone => [$name, $lang, $district, $upazila, $area, $line, $landmark]) {
            $this->buyers[$phone] = compact('name', 'lang', 'district', 'upazila', 'area', 'line', 'landmark');
        }
        foreach (self::CART_PHONES as $phone => [$name]) {
            $this->buyers[$phone] = ['name' => $name, 'lang' => 'bn', 'district' => 'Dhaka', 'upazila' => null, 'area' => 'Mohammadpur', 'line' => 'House 12, Road 4, Block B', 'landmark' => 'Behind Mohammadpur bus stand'];
        }
    }

    /** @return array{operator: User, manager: User, accountant: User} DemoStaffSeeder's users, or the owner */
    private static function staff(): array
    {
        $owner = User::query()->where('email', 'owner@toymart.test')->first()
            ?? throw new RuntimeException('Demo orders need the owner@toymart.test login (DatabaseSeeder creates it).');
        $find = static fn (string $email): User => User::query()->where('email', $email)->first() ?? $owner;

        return [
            'operator' => $find('order_operator@toymart.test'),
            'manager' => $find('manager@toymart.test'),
            'accountant' => $find('accountant@toymart.test'),
        ];
    }

    private static function isDhaka(string $district): bool
    {
        return in_array($district, ['Dhaka', 'Gazipur', 'Narayanganj'], true);
    }

    private function hoursAgo(int $hours): CarbonImmutable
    {
        return $this->now->subHours($hours)->subMinutes($this->rng->getInt(0, 40));
    }

    private function daysAgo(int $days, int $hour): CarbonImmutable
    {
        $days = min($days, $this->history - 1);

        return $this->now->startOfDay()->subDays($days)->setTime($hour, $this->rng->getInt(0, 59));
    }

    /** A Dhaka shopping hour, evenings busiest. */
    private function hour(): int
    {
        return (int) $this->pick(['9' => 3, '10' => 5, '11' => 6, '12' => 5, '13' => 5, '14' => 5, '15' => 5, '16' => 6, '17' => 6, '18' => 7, '19' => 8, '20' => 10, '21' => 10, '22' => 8, '23' => 4]);
    }

    /** Staff work 09:30–21:00 Dhaka: a step outside that waits for the next morning. */
    private function business(CarbonImmutable $at): CarbonImmutable
    {
        $minutes = $at->hour * 60 + $at->minute;

        return match (true) {
            $minutes < 9 * 60 + 30 => $at->setTime(9, 30 + $this->rng->getInt(0, 50)),
            $minutes >= 21 * 60 => $at->addDay()->setTime(10, $this->rng->getInt(0, 50)),
            default => $at,
        };
    }

    private function chance(int $percent): bool
    {
        return $this->rng->getInt(1, 100) <= $percent;
    }

    /**
     * @param  array<int|string, int|float>  $weights
     */
    private function pick(array $weights): string
    {
        $roll = $this->rng->getFloat(0, array_sum($weights));
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return (string) $key;
            }
        }

        return (string) array_key_last($weights);
    }
}
