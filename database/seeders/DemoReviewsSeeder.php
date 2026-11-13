<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Reviews\Actions\ModerateReview;
use App\Domain\Catalog\Reviews\Actions\ReplyToReview;
use App\Domain\Catalog\Reviews\Actions\SubmitReview;
use App\Domain\Catalog\Reviews\DTOs\ReviewData;
use App\Domain\Catalog\Reviews\Enums\ReviewStatus;
use App\Domain\Catalog\Reviews\Models\Review;
use App\Domain\Customer\Enums\CustomerSource;
use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Queries\EloquentCustomerOrderHistory;
use App\Models\Enums\Role;
use App\Models\User;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * Demo product reviews (local only, from DemoDataSeeder, AFTER the orders): 40 reviews over
 * the 16 demo products, English and Bangla, mostly 4–5 stars with some 2–3, in every
 * moderation state — 29 approved, 7 pending, 4 rejected — and a shop reply on 5.
 *
 * Written the way the shop writes them: SubmitReview (as the customer), then ModerateReview
 * and ReplyToReview (as staff). A review is a **verified purchase** when the customer has a
 * delivered line of that product: the seeder looks for delivered order lines (oldest first)
 * and gives each review the next unused one, dated a few days after the delivery. With no
 * delivered orders (the orders seeder off or absent) every review is unverified — fine.
 * Unverified reviews come from customers without such a line; if fewer than
 * MIN_CUSTOMERS customers exist at all, a small pool of review-only customers (phones in
 * the 0199000xxxx range) is created so the reviews still have authors.
 *
 * Ratings — the behaviour chosen: ModerateReview recomputes `products.rating_avg` /
 * `rating_count` from the product's APPROVED reviews (RatingAggregates; the projection can
 * never drift). DemoCatalogSeeder writes the Figma figures (e.g. LEGO 4.70 from 42), which
 * no review backs. Going through the Action replaces them with what the seeded reviews say
 * (LEGO becomes ≈4.67 from 3) — and every product gets at least one approved review, so
 * none is left on an unbacked figure and the PDP's "N reviews" always matches the list it
 * shows. Restoring the Figma figures afterwards would be a write to a projection that the
 * next moderation silently undoes, which is the more surprising behaviour. The design-
 * value assertions (DemoCatalogSeederTest) seed the catalogue without this seeder and are
 * unaffected; the admin E2E run seeds no transactions.
 *
 * Idempotent: if any review already carries one of these bodies, the seeder does nothing.
 * ProductChanged (storefront revalidation, search re-index of the new rating) is silenced
 * while seeding — a fresh database has no storefront cache, and the product model's own
 * save still reaches Scout. The clock is moved per review and always released.
 */
class DemoReviewsSeeder extends Seeder
{
    public const int MIN_CUSTOMERS = 12;

    /**
     * Review-only authors, used only when the database has fewer than MIN_CUSTOMERS customers.
     *
     * @var array<string, array{string, string}> local phone → [name, preferred language]
     */
    public const array FALLBACK_CUSTOMERS = [
        '01990000101' => ['Nusrat Jahan', 'bn'],
        '01990000102' => ['Tahmid Rahman', 'en'],
        '01990000103' => ['Sadia Islam', 'bn'],
        '01990000104' => ['Arif Hossain', 'bn'],
        '01990000105' => ['Farzana Akter', 'en'],
        '01990000106' => ['Rakibul Hasan', 'bn'],
        '01990000107' => ['Mehjabin Chowdhury', 'en'],
        '01990000108' => ['Shakil Ahmed', 'bn'],
        '01990000109' => ['Tania Sultana', 'bn'],
        '01990000110' => ['Imran Kabir', 'en'],
        '01990000111' => ['Rumana Begum', 'bn'],
        '01990000112' => ['Fahim Morshed', 'en'],
    ];

    /**
     * [product slug, stars, locale, title, body, status (a/p/r), shop reply or null].
     *
     * @var list<array{string, int, string, string|null, string, string, string|null}>
     */
    public const array REVIEWS = [
        ['lego-classic-creative-bricks-484', 5, 'en', 'Endless building', 'My son (7) has built a new thing every evening for two weeks. Bricks are genuine and the colours are bright.', 'a', 'Thank you! Share a photo of his best build with us on Facebook.'],
        ['lego-classic-creative-bricks-484', 5, 'bn', 'আসল লেগো', 'ছেলের জন্মদিনে দিয়েছিলাম, বক্স সিল করা অবস্থায় পেয়েছি। দাম একটু বেশি, তবে মান খুবই ভালো।', 'a', null],
        ['lego-classic-creative-bricks-484', 4, 'en', 'Great set, box slightly dented', 'The set itself is perfect. The outer box had a dent from delivery but nothing inside was missing.', 'a', 'Sorry about the box — we have asked our packing team to add extra padding for LEGO sets.'],
        ['fisher-price-smart-stages-puppy', 5, 'bn', 'বাচ্চা খুব পছন্দ করেছে', 'বাংলা গান থাকলে আরও ভালো হতো, কিন্তু ইংরেজি শব্দ আর গোনা শিখছে। ব্যাটারি সাথে দেওয়া ছিল।', 'a', null],
        ['fisher-price-smart-stages-puppy', 4, 'en', 'Cute and educational', 'Sound is a bit loud on the highest setting, but my daughter carries it everywhere.', 'a', null],
        ['fisher-price-smart-stages-puppy', 5, 'en', null, 'Second one we have bought — the first one was a gift for my niece.', 'p', null],
        ['hot-wheels-10-car-gift-pack', 5, 'en', 'Perfect gift pack', 'Ten different cars, no duplicates. Arrived in Chattogram in two days.', 'a', null],
        ['hot-wheels-10-car-gift-pack', 4, 'bn', 'ভালো গাড়ি', 'গাড়িগুলো মজবুত, তবে প্যাকে কোন গাড়ি থাকবে সেটা আগে জানা গেলে ভালো হতো।', 'a', null],
        ['hot-wheels-10-car-gift-pack', 3, 'en', 'Good but not what was pictured', 'Nice cars, but the mix was different from the photo. Kids still loved them.', 'p', null],
        ['hape-pound-tap-bench-xylophone', 5, 'en', 'Beautiful wooden toy', 'Solid wood, smooth edges, no smell. Our 14-month-old plays with it daily.', 'a', null],
        ['hape-pound-tap-bench-xylophone', 5, 'bn', 'মান অসাধারণ', 'কাঠের কাজ খুব সুন্দর। জাইলোফোনের সুরও ঠিক আছে।', 'a', null],
        ['mideer-wooden-shape-sorting-puzzle', 4, 'bn', 'ছোটদের জন্য ভালো', 'আকারগুলো বড়, তাই মুখে দেওয়ার ভয় নেই। রং একটু হালকা।', 'a', null],
        ['mideer-wooden-shape-sorting-puzzle', 5, 'en', 'Learning shapes fast', 'My 2-year-old now names every shape. Good value for a wooden puzzle.', 'a', null],
        ['mideer-wooden-shape-sorting-puzzle', 2, 'en', 'One piece was cracked', 'The triangle piece arrived with a crack. Customer service offered a replacement.', 'a', 'We are sorry — the replacement piece is on its way. Thank you for your patience.'],
        ['huanger-baby-activity-gym-play-mat', 4, 'en', 'Soft and washable', 'The mat washes well. The arches are a little wobbly but fine for tummy time.', 'a', null],
        ['huanger-baby-activity-gym-play-mat', 5, 'bn', 'নবজাতকের জন্য দারুণ', 'আমার তিন মাসের মেয়ে ঝুলন্ত খেলনা দেখে খুব খুশি হয়।', 'a', null],
        ['huanger-baby-activity-gym-play-mat', 3, 'bn', null, 'ম্যাটটা যতটা মোটা ভেবেছিলাম ততটা নয়।', 'p', null],
        ['bruder-man-tga-dump-truck', 5, 'en', 'Built like a real truck', 'Huge and heavy. The tipper and tailgate work properly. Worth the price.', 'a', null],
        ['bruder-man-tga-dump-truck', 5, 'bn', 'ছেলের প্রিয় খেলনা', 'বাইরে মাটিতে খেলেও কিছু হয়নি। খুবই মজবুত।', 'p', null],
        ['rubiks-cube-3x3-original', 5, 'en', 'Smooth turning', 'Much smoother than the cheap cubes from the market. Stickers are good quality.', 'a', null],
        ['rubiks-cube-3x3-original', 4, 'bn', 'আসল রুবিক্স', 'প্রথমে একটু শক্ত ছিল, কয়েকদিন পর ঠিক হয়ে গেছে।', 'a', null],
        ['rubiks-cube-3x3-original', 5, 'en', null, 'Bought during the flash sale, great price.', 'a', null],
        ['play-doh-rainbow-starter-10-pack', 5, 'bn', 'রং খুব সুন্দর', 'দশটা রংই নরম ছিল, শুকিয়ে যায়নি। বাচ্চারা ঘণ্টার পর ঘণ্টা খেলে।', 'a', null],
        ['play-doh-rainbow-starter-10-pack', 4, 'en', 'Kids love it', 'Keep the lids closed or it dries out. Good pack for the price.', 'a', null],
        ['barbie-dreamhouse-pool-party-doll', 4, 'en', 'Lovely doll', 'The pool accessories are small, so not for younger siblings. Daughter is very happy.', 'a', null],
        ['barbie-dreamhouse-pool-party-doll', 5, 'bn', 'মেয়ের জন্মদিনের উপহার', 'ডেলিভারি সময়মতো পেয়েছি, পুতুলটা ছবির মতোই।', 'p', null],
        ['clementoni-galileo-my-first-microscope', 4, 'en', 'Good first microscope', 'Easy for a 9-year-old to use. The included slides are a nice touch.', 'a', null],
        ['clementoni-galileo-my-first-microscope', 3, 'bn', 'মোটামুটি', 'বাচ্চাদের জন্য ঠিক আছে, তবে বেশি জুমে ছবি ঝাপসা হয়।', 'a', 'আপনার মতামতের জন্য ধন্যবাদ। কম জুমে আলো বাড়িয়ে দেখলে ছবি পরিষ্কার হয়।'],
        ['little-tikes-first-slide', 5, 'en', 'Sturdy and easy to assemble', 'Took ten minutes to put together. Our twins use it every day indoors.', 'a', null],
        ['little-tikes-first-slide', 4, 'bn', 'ভালো স্লাইড', 'ঘরের ভেতরে জায়গা একটু বেশি লাগে, কিন্তু খুব মজবুত।', 'p', null],
        ['intex-dinosaur-inflatable-kids-pool', 4, 'en', 'Summer fun', 'Holds up well, inflates in a few minutes with a foot pump.', 'a', null],
        ['intex-dinosaur-inflatable-kids-pool', 2, 'bn', 'ফুটো ছিল', 'দ্বিতীয় দিনেই একটা ছোট ফুটো হয়েছে, প্যাচ দিয়ে ঠিক করতে হয়েছে।', 'a', null],
        ['crayola-washable-super-tips-20-markers', 5, 'en', 'Actually washable', 'Came off the wall and my son\'s school shirt with plain water. Bright colours.', 'a', null],
        ['crayola-washable-super-tips-20-markers', 4, 'bn', null, 'রংগুলো সুন্দর, তবে দুইটা মার্কারের ক্যাপ একটু ঢিলা।', 'p', null],
        ['winfun-baby-walker-push-car', 4, 'bn', 'হাঁটা শিখছে', 'এটা ধরে আমার ছেলে হাঁটা শিখছে। শব্দগুলো একটু জোরে।', 'a', null],
        ['melissa-doug-wooden-doctor-kit', 5, 'en', 'Hours of pretend play', 'All wooden, nothing flimsy. The stethoscope is the favourite.', 'a', 'Thank you! Little doctors make the best patients.'],
        ['melissa-doug-wooden-doctor-kit', 1, 'en', 'Best toys cheaper on our page', 'Same toys half price — message our Facebook page for a wholesale offer.', 'r', null],
        ['lego-classic-creative-bricks-484', 1, 'bn', null, 'অর্ডার দিতে গিয়ে ওয়েবসাইট দুবার লোড হয়নি।', 'r', null],
        ['rubiks-cube-3x3-original', 1, 'en', 'asdf', 'test test test', 'r', null],
        ['bruder-man-tga-dump-truck', 2, 'en', 'Courier was late', 'The courier came a day late and did not call first. Nothing to do with the truck.', 'r', null],
    ];

    /** @var array<int, list<int>> customer id → products reviewed in this run */
    private array $reviewed = [];

    /** @var array<int, true> delivered order lines already given a review in this run */
    private array $usedLines = [];

    public function run(): void
    {
        if (Review::query()->where('body', self::REVIEWS[0][4])->exists()) {
            return;
        }

        $moderator = (DemoStaffSeeder::user(Role::Manager) ?? User::query()->where('email', 'owner@toymart.test')->first() ?? User::query()->orderBy('id')->first())
            ?? throw new RuntimeException('DemoReviewsSeeder needs a staff user to moderate as.');

        Event::fakeFor(function () use ($moderator): void {
            try {
                $this->seedReviews($moderator->id);
            } finally {
                Carbon::setTestNow(null);
                CarbonImmutable::setTestNow(null);
            }
        }, [ProductChanged::class]);
    }

    private function seedReviews(int $moderatorId): void
    {
        $products = Product::query()->whereIn('slug', array_unique(array_column(self::REVIEWS, 0)))->pluck('id', 'slug');
        $delivered = $this->deliveredLines();
        $pool = $this->customerPool();
        $now = CarbonImmutable::now();
        $history = max(1, (int) config('demo.history_days', 60));

        foreach (self::REVIEWS as $i => [$slug, $stars, $locale, $title, $body, $status, $reply]) {
            $productId = (int) ($products[$slug] ?? throw new RuntimeException("Product {$slug} missing: run DemoCatalogSeeder first."));

            $line = $this->takeDeliveredLine($delivered, $productId);
            if ($line !== null) {
                $customerId = $line['customer_id'];
                $at = $line['delivered_at']->addDays(2 + $i % 5)->setTime(10 + $i % 10, 15);
                $orderItemId = $line['order_item_id'];
            } else {
                $customerId = $this->freeCustomer($pool, $productId);
                $at = $now->subDays(1 + ($i * 7) % $history)->setTime(9 + $i % 12, ($i * 7) % 60);
                $orderItemId = null;
            }
            $at = $at->min($now->subHours(2));
            $this->reviewed[$customerId][] = $productId;

            $review = self::at($at, fn (): Review => app(SubmitReview::class)->handle(
                Customer::query()->findOrFail($customerId),
                new ReviewData($productId, $stars, $title, $body, null, $orderItemId, $locale),
            ));

            if ($status === 'p') {
                continue;
            }
            $moderated = self::at($at->addHours(20)->min($now->subHour()), fn (): Review => app(ModerateReview::class)->handle(
                $review,
                $status === 'a' ? ReviewStatus::Approved : ReviewStatus::Rejected,
                $moderatorId,
            ));
            if ($reply !== null) {
                self::at($at->addHours(26)->min($now->subMinutes(30)), fn (): Review => app(ReplyToReview::class)->handle($moderated, $reply));
            }
        }
    }

    /**
     * Delivered lines (oldest first), grouped by product, each with its delivery instant.
     *
     * @return array<int, list<array{order_item_id: int, customer_id: int, delivered_at: CarbonImmutable}>>
     */
    private function deliveredLines(): array
    {
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotNull('orders.customer_id')
            ->whereIn('orders.status', array_map(static fn (OrderStatus $s): string => $s->value, EloquentCustomerOrderHistory::DELIVERED))
            ->whereColumn('order_items.quantity_returned', '<', 'order_items.quantity')
            ->whereNotExists(static fn ($q) => $q->selectRaw('1')->from('reviews')->whereColumn('reviews.order_item_id', 'order_items.id'))
            ->orderBy('order_items.id')
            ->get(['order_items.id', 'order_items.product_id', 'orders.customer_id', 'orders.delivered_at', 'orders.placed_at', 'orders.created_at']);

        $out = [];
        foreach ($rows as $row) {
            $when = $row->delivered_at ?? $row->placed_at ?? $row->created_at;
            $out[(int) $row->product_id][] = [
                'order_item_id' => (int) $row->id,
                'customer_id' => (int) $row->customer_id,
                'delivered_at' => CarbonImmutable::parse((string) $when),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, list<array{order_item_id: int, customer_id: int, delivered_at: CarbonImmutable}>>  $delivered
     * @return array{order_item_id: int, customer_id: int, delivered_at: CarbonImmutable}|null
     */
    private function takeDeliveredLine(array $delivered, int $productId): ?array
    {
        foreach ($delivered[$productId] ?? [] as $line) {
            if (! isset($this->usedLines[$line['order_item_id']]) && ! in_array($productId, $this->reviewed[$line['customer_id']] ?? [], true)) {
                $this->usedLines[$line['order_item_id']] = true;

                return $line;
            }
        }

        return null;
    }

    /**
     * Customers to author unverified reviews, oldest first; tops up with FALLBACK_CUSTOMERS
     * when there are too few.
     *
     * @return list<int>
     */
    private function customerPool(): array
    {
        if (Customer::query()->count() < self::MIN_CUSTOMERS) {
            foreach (self::FALLBACK_CUSTOMERS as $phone => [$name, $language]) {
                Customer::query()->firstOrCreate(['phone' => PhoneNumber::parse($phone)->e164()], [
                    'name' => $name,
                    'preferred_language' => $language,
                    'source' => CustomerSource::Web,
                ]);
            }
        }

        return array_values(array_map(intval(...), Customer::query()->where('status', 'active')->orderBy('id')->pluck('id')->all()));
    }

    /** @param  list<int>  $pool */
    private function freeCustomer(array $pool, int $productId): int
    {
        $existing = Review::query()->where('product_id', $productId)->pluck('customer_id')->map(intval(...))->all();
        // Spread authors: start at a product-dependent offset rather than always the first customer.
        $count = count($pool);
        for ($k = 0; $k < $count; $k++) {
            $id = $pool[($productId * 5 + $k) % $count];
            if (! in_array($id, $existing, true) && ! in_array($productId, $this->reviewed[$id] ?? [], true)) {
                return $id;
            }
        }

        throw new RuntimeException("No customer left to review product {$productId}.");
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
