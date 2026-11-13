<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Marketing\Actions\CreateCoupon;
use App\Domain\Marketing\Actions\DeactivateCoupon;
use App\Domain\Marketing\Actions\DeactivatePromotion;
use App\Domain\Marketing\Actions\GenerateCouponCodes;
use App\Domain\Marketing\Actions\SavePromotion;
use App\Domain\Marketing\DTOs\CouponData;
use App\Domain\Marketing\DTOs\PromotionData;
use App\Domain\Marketing\Enums\CouponType;
use App\Domain\Marketing\Enums\ProductScopeType;
use App\Domain\Marketing\Enums\PromotionType;
use App\Domain\Marketing\Events\PromotionChanged;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\Promotion;
use App\Models\Enums\Role;
use App\Models\User;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * Demo marketing (local only, from DemoDataSeeder, BEFORE the orders): coupons, automatic
 * promotions and flash sales in every state the admin lists and the storefront shows.
 *
 * Coupons (CreateCoupon / GenerateCouponCodes / DeactivateCoupon):
 *   WELCOME100   ৳100 off from ৳1,000 — active, 5,000 uses        } ORDER_COUPONS: the order
 *   TOY10        10% off, capped ৳300, from ৳800 — active          } seeder applies these
 *   BABY15       15% off Baby & Toddler — active, category-scoped
 *   FREESHIP     free delivery from ৳1,500 — active
 *   PUJA200      expired (ended before the order history starts)
 *   FLASH50      deactivated by an admin
 *   WINTER250    not yet started (starts in 10 days)
 *   THANKS-…     a batch of 20 single-use codes (`thank-you-2026`), generated
 *
 * WELCOME100 and TOY10 are valid from before the first demo order to open-ended, and are
 * neither first-order-only nor limited per customer, so the order seeder can apply them on
 * any order at or above the minimum without a rejection.
 *
 * Promotions (SavePromotion / DeactivatePromotion), one of each engine type:
 *   Puzzle week          discount 10% on Puzzles & Games     running, priority 20, stackable
 *   Art supplies 3-for-2 buy_x_get_y 2+1 on Art & Craft      running, priority 10, NOT stackable
 *   Free delivery ৳3,000 free_delivery                       running, priority 0
 *   Winter spend & save  tiered 5% ≥ ৳3,000, 8% ≥ ৳6,000      scheduled (starts in 7 days)
 *   Puzzle + markers set bundle at a set price               deactivated
 *   Puja sale            discount 15% storewide, budget      ended
 *   Weekend flash sale   flash_sale on 4 variants            running, priority 100, NOT stackable, no coupons
 *   Pohela Falgun flash  flash_sale on 3 variants            ended
 *
 * Stacking, as the engine applies it (docs/api/promotions.md): highest priority first; a
 * non-stackable promotion that takes money off stops the chain. So a cart with a flash-sale
 * cube stops at the flash sale (no Puzzle-week 10% on top, and no coupon on that line);
 * a cart of markers gets 3-for-2 and then stops; a puzzle-only cart gets Puzzle week and
 * still reaches free delivery.
 *
 * Timing, so the seeded order history is not repriced by surprise: everything running
 * starts at the moment of seeding (the order seeder places its history in the past), and
 * everything ended or expired ended before the first demo order. Each row is created with
 * the clock set to when an admin would have created it, and the clock is always released.
 * Day boundaries are Dhaka days, stored UTC (as the admin form stores them). Flash
 * allotments are capped at half the units available when seeded.
 *
 * Idempotent on the coupon code, the batch name and the promotion name. PromotionChanged is
 * silenced (storefront revalidation only; nothing projects from it).
 */
class DemoMarketingSeeder extends Seeder
{
    /** Codes the order seeder applies; both stay valid across the whole order history. */
    public const array ORDER_COUPONS = ['WELCOME100', 'TOY10'];

    public const string BATCH = 'thank-you-2026';

    /**
     * code → terms. `window` = [start, end] in days relative to now (end null = open-ended);
     * `deactivate` → created active, then deactivated through DeactivateCoupon.
     *
     * @var array<string, array{description: string, type: CouponType, value: string, max?: string, min: string, limit: int, per_customer?: int, window: array{int, int|null}, category?: string, deactivate?: true}>
     */
    public const array COUPONS = [
        'WELCOME100' => ['description' => '৳100 off your order of ৳1,000 or more', 'type' => CouponType::Fixed, 'value' => '100.00', 'min' => '1000.00', 'limit' => 5000, 'window' => [-120, null]],
        'TOY10' => ['description' => '10% off toys, up to ৳300', 'type' => CouponType::Percent, 'value' => '10.00', 'max' => '300.00', 'min' => '800.00', 'limit' => 5000, 'window' => [-120, null]],
        'BABY15' => ['description' => '15% off Baby & Toddler, up to ৳400', 'type' => CouponType::Percent, 'value' => '15.00', 'max' => '400.00', 'min' => '1500.00', 'limit' => 300, 'per_customer' => 1, 'window' => [-20, 40], 'category' => 'baby-toddler'],
        'FREESHIP' => ['description' => 'Free delivery on orders of ৳1,500 or more', 'type' => CouponType::FreeShipping, 'value' => '0.00', 'min' => '1500.00', 'limit' => 1000, 'per_customer' => 2, 'window' => [-30, 30]],
        'PUJA200' => ['description' => 'Puja: ৳200 off ৳2,500 or more', 'type' => CouponType::Fixed, 'value' => '200.00', 'min' => '2500.00', 'limit' => 500, 'per_customer' => 1, 'window' => [-110, -95]],
        'FLASH50' => ['description' => '৳50 off — withdrawn after a pricing mistake', 'type' => CouponType::Fixed, 'value' => '50.00', 'min' => '500.00', 'limit' => 200, 'window' => [-15, 15], 'deactivate' => true],
        'WINTER250' => ['description' => 'Winter: ৳250 off ৳3,000 or more', 'type' => CouponType::Fixed, 'value' => '250.00', 'min' => '3000.00', 'limit' => 1000, 'per_customer' => 1, 'window' => [10, 45]],
    ];

    /**
     * The running flash sale: product slug (its default variant) → [sale price, planned allotment].
     *
     * @var array<string, array{string, int}>
     */
    public const array LIVE_FLASH = [
        'rubiks-cube-3x3-original' => ['749.00', 12],
        'play-doh-rainbow-starter-10-pack' => ['899.00', 10],
        'hot-wheels-10-car-gift-pack' => ['1490.00', 8],
        'mideer-wooden-shape-sorting-puzzle' => ['990.00', 6],
    ];

    /** @var array<string, array{string, int}> */
    private const array ENDED_FLASH = [
        'crayola-washable-super-tips-20-markers' => ['620.00', 15],
        'fisher-price-smart-stages-puppy' => ['2690.00', 6],
        'melissa-doug-wooden-doctor-kit' => ['2290.00', 6],
    ];

    public function run(): void
    {
        Event::fakeFor(function (): void {
            try {
                $this->coupons();
                $this->couponBatch();
                $this->promotions();
            } finally {
                Carbon::setTestNow(null);
                CarbonImmutable::setTestNow(null);
            }
        }, [PromotionChanged::class]);
    }

    private function coupons(): void
    {
        $actor = self::actorId();
        $now = CarbonImmutable::now();

        foreach (self::COUPONS as $code => $c) {
            if (Coupon::query()->where('code', $code)->exists()) {
                continue;
            }
            [$from, $to] = $c['window'];
            $startsAt = $now->addDays($from)->setTimezone(DhakaDate::zone())->startOfDay()->utc();
            $endsAt = $to === null ? null : $now->addDays($to)->setTimezone(DhakaDate::zone())->endOfDay()->utc();
            $scopeIds = isset($c['category']) ? [self::categoryId($c['category'])] : [];

            self::at($startsAt->subDays(2)->min($now), function () use ($code, $c, $startsAt, $endsAt, $scopeIds, $actor): void {
                $coupon = app(CreateCoupon::class)->handle(new CouponData(
                    code: $code,
                    description: $c['description'],
                    type: $c['type'],
                    value: Money::of($c['value']),
                    maxDiscount: isset($c['max']) ? Money::of($c['max']) : null,
                    minOrderAmount: Money::of($c['min']),
                    usageLimitTotal: $c['limit'],
                    usageLimitPerCustomer: $c['per_customer'] ?? null,
                    startsAt: $startsAt,
                    endsAt: $endsAt,
                    appliesTo: $scopeIds === [] ? ProductScopeType::All : ProductScopeType::Categories,
                    scopeIds: $scopeIds,
                    excludes: [],
                    firstOrderOnly: false,
                    isActive: true,
                ), $actor);

                if (isset($c['deactivate'])) {
                    app(DeactivateCoupon::class)->handle($coupon);
                }
            });
        }
    }

    /** 20 single-use thank-you codes, as the coupon builder generates them. */
    private function couponBatch(): void
    {
        if (Coupon::query()->where('batch', self::BATCH)->exists()) {
            return;
        }
        $now = CarbonImmutable::now();

        self::at($now->subDays(5), fn (): array => app(GenerateCouponCodes::class)->handle(new CouponData(
            code: '',
            description: 'Thank-you code for repeat customers: ৳150 off ৳1,500',
            type: CouponType::Fixed,
            value: Money::of('150.00'),
            maxDiscount: null,
            minOrderAmount: Money::of('1500.00'),
            usageLimitTotal: 1,
            usageLimitPerCustomer: 1,
            startsAt: $now->subDays(5)->setTimezone(DhakaDate::zone())->startOfDay()->utc(),
            endsAt: $now->addDays(60)->setTimezone(DhakaDate::zone())->endOfDay()->utc(),
            appliesTo: ProductScopeType::All,
            scopeIds: [],
            excludes: [],
            firstOrderOnly: false,
            isActive: true,
        ), 20, 'THANKS', 8, self::BATCH, self::actorId()));
    }

    private function promotions(): void
    {
        $now = CarbonImmutable::now();
        $history = max(1, (int) config('demo.history_days', 60));
        $endedAt = $now->subDays($history + 10)->setTimezone(DhakaDate::zone())->startOfDay()->utc();
        $scope = static fn (string $slug): array => ['applies_to' => 'categories', 'ids' => [self::categoryId($slug)]];

        $this->promotion(new PromotionData(
            name: 'Puzzle week', nameBn: 'পাজল সপ্তাহ', description: '10% off every puzzle and brain teaser.',
            type: PromotionType::Discount, priority: 20, isStackable: true, allowsCoupons: true,
            rules: ['scope' => $scope('puzzles-games'), 'type' => 'percent', 'value' => '10.00'],
            startsAt: $now, endsAt: $now->addDays(10)->setTimezone(DhakaDate::zone())->endOfDay()->utc(), isActive: true,
            usageLimitTotal: null, usageLimitPerCustomer: null, budget: Money::of('25000.00'),
        ), $now->subDay());

        $this->promotion(new PromotionData(
            name: 'Art supplies 3-for-2', nameBn: 'আর্ট সামগ্রী ৩টি নিলে ১টি ফ্রি', description: 'Buy two art & craft items, the cheapest third is free.',
            type: PromotionType::BuyXGetY, priority: 10, isStackable: false, allowsCoupons: true,
            rules: ['scope' => $scope('art-craft'), 'buy' => 2, 'get' => 1, 'percent' => '100'],
            startsAt: $now, endsAt: $now->addDays(14)->setTimezone(DhakaDate::zone())->endOfDay()->utc(), isActive: true,
            usageLimitTotal: null, usageLimitPerCustomer: 3, budget: null,
        ), $now->subDay());

        $this->promotion(new PromotionData(
            name: 'Free delivery over ৳3,000', nameBn: '৳3,000-এর বেশি কেনাকাটায় ফ্রি ডেলিভারি', description: 'Delivery is free when the goods reach ৳3,000.',
            type: PromotionType::FreeDelivery, priority: 0, isStackable: true, allowsCoupons: true,
            rules: ['scope' => ['applies_to' => 'all'], 'min_subtotal' => '3000.00'],
            startsAt: $now, endsAt: null, isActive: true,
            usageLimitTotal: null, usageLimitPerCustomer: null, budget: null,
        ), $now->subDay());

        $this->promotion(new PromotionData(
            name: 'Winter spend & save', nameBn: 'শীতের কেনাকাটায় সাশ্রয়', description: '5% off from ৳3,000, 8% off from ৳6,000.',
            type: PromotionType::Tiered, priority: 5, isStackable: true, allowsCoupons: true,
            rules: ['scope' => ['applies_to' => 'all'], 'tiers' => [
                ['min_subtotal' => '3000.00', 'type' => 'percent', 'value' => '5.00'],
                ['min_subtotal' => '6000.00', 'type' => 'percent', 'value' => '8.00'],
            ], 'max_discount' => '1000.00'],
            startsAt: $now->addDays(7)->setTimezone(DhakaDate::zone())->startOfDay()->utc(), endsAt: $now->addDays(21)->setTimezone(DhakaDate::zone())->endOfDay()->utc(), isActive: true,
            usageLimitTotal: 2000, usageLimitPerCustomer: 2, budget: Money::of('100000.00'),
        ), $now->subDays(2));

        $bundle = $this->promotion(new PromotionData(
            name: 'Puzzle + markers set', nameBn: 'পাজল + মার্কার সেট', description: 'The shape puzzle and 20 markers together for ৳1,790.',
            type: PromotionType::Bundle, priority: 15, isStackable: true, allowsCoupons: false,
            rules: ['scope' => ['applies_to' => 'all'], 'product_ids' => [
                self::productId('mideer-wooden-shape-sorting-puzzle'), self::productId('crayola-washable-super-tips-20-markers'),
            ], 'type' => 'price', 'value' => '1790.00'],
            startsAt: $now->subDays(12)->setTimezone(DhakaDate::zone())->startOfDay()->utc(), endsAt: $now->addDays(18)->setTimezone(DhakaDate::zone())->endOfDay()->utc(), isActive: true,
            usageLimitTotal: null, usageLimitPerCustomer: null, budget: null,
        ), $now->subDays(13));
        if ($bundle !== null) {
            self::at($now->subDays(12), fn (): Promotion => app(DeactivatePromotion::class)->handle($bundle));
        }

        $this->promotion(new PromotionData(
            name: 'Puja sale', nameBn: 'পূজার ছাড়', description: '15% off storewide for Durga Puja.',
            type: PromotionType::Discount, priority: 30, isStackable: true, allowsCoupons: true,
            rules: ['scope' => ['applies_to' => 'all'], 'type' => 'percent', 'value' => '15.00', 'max_discount' => '750.00'],
            startsAt: $endedAt->subDays(6), endsAt: $endedAt, isActive: true,
            usageLimitTotal: null, usageLimitPerCustomer: 2, budget: Money::of('60000.00'),
        ), $endedAt->subDays(8));

        $this->promotion(new PromotionData(
            name: 'Weekend flash sale', nameBn: 'উইকেন্ড ফ্ল্যাশ সেল', description: 'Four favourites at flash prices while the allotment lasts.',
            type: PromotionType::FlashSale, priority: 100, isStackable: false, allowsCoupons: false,
            rules: ['scope' => ['applies_to' => 'all']],
            startsAt: $now, endsAt: $now->addDays(3)->setTimezone(DhakaDate::zone())->endOfDay()->utc(), isActive: true,
            usageLimitTotal: null, usageLimitPerCustomer: null, budget: null,
            flashItems: self::flashItems(self::LIVE_FLASH, true),
        ), $now->subDay());

        $this->promotion(new PromotionData(
            name: 'Pohela Falgun flash sale', nameBn: 'পহেলা ফাল্গুন ফ্ল্যাশ সেল', description: 'A one-day flash sale.',
            type: PromotionType::FlashSale, priority: 100, isStackable: false, allowsCoupons: false,
            rules: ['scope' => ['applies_to' => 'all']],
            startsAt: $endedAt->subDays(20), endsAt: $endedAt->subDays(19), isActive: true,
            usageLimitTotal: null, usageLimitPerCustomer: null, budget: null,
            flashItems: self::flashItems(self::ENDED_FLASH, false),
        ), $endedAt->subDays(22));
    }

    /** Create once (by name), with the clock at `$createdAt`; null when it already exists. */
    private function promotion(PromotionData $data, CarbonImmutable $createdAt): ?Promotion
    {
        if (Promotion::query()->where('name', $data->name)->exists()) {
            return null;
        }

        return self::at($createdAt, fn (): Promotion => app(SavePromotion::class)->handle($data, null, self::actorId()));
    }

    /**
     * @param  array<string, array{string, int}>  $plan
     * @return list<array{variant_id: int, sale_price: Money, allotment: int, per_order_limit: int|null}>
     */
    private static function flashItems(array $plan, bool $capToStock): array
    {
        $items = [];
        foreach ($plan as $slug => [$salePrice, $allotment]) {
            $variant = ProductVariant::query()->where('product_id', self::productId($slug))->where('is_default', true)->firstOrFail();
            if ($capToStock) {
                $available = (int) StockLevel::query()->where('variant_id', $variant->id)->selectRaw('COALESCE(SUM(on_hand - reserved), 0) as n')->value('n');
                $allotment = min($allotment, intdiv($available, 2));
                if ($allotment < 1) {
                    continue;
                }
            }
            $items[] = ['variant_id' => $variant->id, 'sale_price' => Money::of($salePrice), 'allotment' => $allotment, 'per_order_limit' => 2];
        }
        usort($items, static fn (array $a, array $b): int => $a['variant_id'] <=> $b['variant_id']);

        return $items;
    }

    private static function categoryId(string $slug): int
    {
        return (int) (Category::query()->where('slug', $slug)->value('id') ?? throw new RuntimeException("Category {$slug} missing: run CategoryDemoSeeder first."));
    }

    private static function productId(string $slug): int
    {
        return (int) (Product::query()->where('slug', $slug)->value('id') ?? throw new RuntimeException("Product {$slug} missing: run DemoCatalogSeeder first."));
    }

    /** The marketing manager when DemoStaffSeeder ran, else the local owner, else nobody. */
    private static function actorId(): ?int
    {
        $user = DemoStaffSeeder::user(Role::Manager) ?? User::query()->where('email', 'owner@toymart.test')->first();

        return $user?->id;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private static function at(CarbonImmutable $instant, callable $fn): mixed
    {
        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);
        try {
            return $fn();
        } finally {
            Carbon::setTestNow(null);
            CarbonImmutable::setTestNow(null);
        }
    }
}
