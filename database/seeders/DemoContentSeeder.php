<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Enums\AgeBand;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Content\Actions\SaveBanner;
use App\Domain\Content\Actions\SaveMenu;
use App\Domain\Content\Actions\SaveTestimonial;
use App\Domain\Content\Blog\Actions\SaveBlogPost;
use App\Domain\Content\Blog\Actions\SaveBlogTerm;
use App\Domain\Content\Blog\DTOs\BlogPostData;
use App\Domain\Content\Blog\DTOs\BlogTermData;
use App\Domain\Content\Blog\DTOs\GuidePickData;
use App\Domain\Content\Blog\DTOs\GuideSectionData;
use App\Domain\Content\Blog\Enums\PostStatus;
use App\Domain\Content\Blog\Enums\PostType;
use App\Domain\Content\Blog\Models\BlogAuthor;
use App\Domain\Content\Blog\Models\BlogCategory;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Content\Blog\Models\BlogTag;
use App\Domain\Content\DTOs\BannerData;
use App\Domain\Content\DTOs\MenuItemData;
use App\Domain\Content\DTOs\TestimonialData;
use App\Domain\Content\Enums\BannerPlacement;
use App\Domain\Content\Enums\MenuLinkType;
use App\Domain\Content\Enums\MenuLocation;
use App\Domain\Content\Events\BannersChanged;
use App\Domain\Content\Events\MenusChanged;
use App\Domain\Content\Events\TestimonialsChanged;
use App\Domain\Content\Models\Banner;
use App\Domain\Content\Models\CmsPage;
use App\Domain\Content\Models\Menu;
use App\Domain\Content\Models\Testimonial;
use App\Domain\Seo\Events\SeoPagesStale;
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
 * Demo storefront content (local only, from DemoDataSeeder): the blog, banners, the three
 * menus and testimonials, so every content screen in the admin and every content slot on
 * the storefront has something real to show. Builds on what the base seed already made —
 * CmsHomeBlocksSeeder's homepage (its testimonials and blog-teaser blocks now fill) and
 * StaticPagesSeeder's pages (linked from the footer; they stay drafts the owner publishes,
 * so the storefront hides those links until then, exactly as the menu builder intends).
 *
 * - Blog (SaveBlogTerm / SaveBlogPost): 4 categories, 8 tags, 2 authors and 8 posts —
 *   6 published (spread over the order-history window, English and Bangla), 1 draft and
 *   1 scheduled; one is a buying guide whose sections pick live demo products.
 * - Banners (SaveBanner): announcement bar, info strip (the only checkout-safe placement),
 *   category-page banner on Baby & Toddler, a popup for the flash sale, and one scheduled
 *   announcement for the winter promotion.
 * - Menus (SaveMenu): header (category mega menu, brands, the guide), footer (shop / help /
 *   about columns) and mobile, all pointing at real categories, brands, pages and posts.
 * - Testimonials (SaveTestimonial): 6, of which 5 published.
 *
 * Images: no network. Covers and banner images reuse the demo product photos already in the
 * media library (DemoCatalogSeeder with DEMO_SEED_IMAGES on); without them the image fields
 * stay empty, which every placement and the blog accept.
 *
 * Idempotent: terms and posts on slug, banners on name, testimonials on name, menus on
 * location (an existing menu — the owner's — is never replaced). Revalidation-only events
 * are silenced and the storefront URL is blanked for the run, so the blog's cache purge
 * job is a no-op; nothing projects from either.
 */
class DemoContentSeeder extends Seeder
{
    public const string GUIDE_SLUG = 'best-educational-toys-by-age';

    /** @var array<string, array{string, string, string, string}> slug → [name, name_bn, description, description_bn] */
    public const array CATEGORIES = [
        'play-and-learning' => ['Play & learning', 'খেলা ও শেখা', 'How children learn through play, age by age.', 'বয়স অনুযায়ী খেলার মাধ্যমে শিশুরা কীভাবে শেখে।'],
        'gift-guides' => ['Gift guides', 'উপহার গাইড', 'Gift ideas for birthdays, Eid and every occasion.', 'জন্মদিন, ঈদ ও সব উপলক্ষের উপহারের আইডিয়া।'],
        'parenting-tips' => ['Parenting tips', 'প্যারেন্টিং টিপস', 'Practical ideas for busy parents.', 'ব্যস্ত বাবা-মায়ের জন্য কাজের পরামর্শ।'],
        'toy-safety' => ['Toy safety', 'খেলনার নিরাপত্তা', 'Choosing safe toys for every age.', 'প্রতিটি বয়সের জন্য নিরাপদ খেলনা বেছে নেওয়া।'],
    ];

    /** @var array<string, array{string, string}> */
    public const array TAGS = [
        'stem' => ['STEM', 'স্টেম'],
        'toddlers' => ['Toddlers', 'ছোট শিশু'],
        'gifts' => ['Gifts', 'উপহার'],
        'screen-free' => ['Screen-free', 'স্ক্রিন-মুক্ত'],
        'safety' => ['Safety', 'নিরাপত্তা'],
        'eid' => ['Eid', 'ঈদ'],
        'crafts' => ['Crafts', 'হাতের কাজ'],
        'outdoor' => ['Outdoor play', 'বাইরের খেলা'],
    ];

    /**
     * Posts. `days` = published N days ago (published), or due in N days (scheduled); null for a draft.
     * `cover` = a demo product SKU whose library photo is the cover, when present.
     *
     * @var array<string, array{type: PostType, status: PostStatus, days: int|null, title: string, title_bn: string, excerpt: string, excerpt_bn: string|null, body: string, body_bn: string|null, category: string, tags: list<string>, author: string, cover: string, featured?: true, related?: list<string>}>
     */
    public const array POSTS = [
        self::GUIDE_SLUG => [
            'type' => PostType::BuyingGuide, 'status' => PostStatus::Published, 'days' => 41, 'featured' => true,
            'title' => 'The best educational toys by age (2026 guide)', 'title_bn' => 'বয়স অনুযায়ী সেরা শিক্ষামূলক খেলনা (২০২৬ গাইড)',
            'excerpt' => 'From the first rattle to the first microscope: what helps a child learn at each stage, and what we would buy.',
            'excerpt_bn' => 'প্রথম ঝুনঝুনি থেকে প্রথম মাইক্রোস্কোপ: প্রতিটি বয়সে শিশুর শেখায় কী সাহায্য করে, আর আমরা কী কিনতাম।',
            'body' => '<p>Children learn by doing. The right toy at the right age invites exactly the kind of play a child is ready for — grasping, sorting, building, then asking why.</p><p>Every pick below is in stock at Toy Mart BD and chosen by our team with parents in Dhaka and Chattogram.</p>',
            'body_bn' => '<p>শিশুরা করে করে শেখে। সঠিক বয়সে সঠিক খেলনা ঠিক সেই খেলাটাই ডেকে আনে, যার জন্য শিশু তৈরি — ধরা, সাজানো, বানানো, তারপর প্রশ্ন করা।</p><p>নিচের প্রতিটি খেলনা টয় মার্ট বিডিতে পাওয়া যায়।</p>',
            'category' => 'play-and-learning', 'tags' => ['stem', 'toddlers'], 'author' => 'sabina-yasmin', 'cover' => 'TMB-CLE-MICRO',
        ],
        'screen-free-activities-for-rainy-days' => [
            'type' => PostType::Article, 'status' => PostStatus::Published, 'days' => 52,
            'title' => '7 screen-free activities for rainy days', 'title_bn' => 'বর্ষার দিনে স্ক্রিন ছাড়া ৭টি মজার খেলা',
            'excerpt' => 'Monsoon afternoons indoors do not have to mean cartoons. Seven ideas that need little more than a table.',
            'excerpt_bn' => 'বর্ষার বিকেলে ঘরে বসে মানেই কার্টুন নয়। একটা টেবিলেই হয়ে যায় এমন সাতটি আইডিয়া।',
            'body' => '<h2>1. A dough bakery</h2><p>Set up a pretend bakery with modelling dough and a rolling pin.</p><h2>2. Brick challenges</h2><p>Call out a challenge — the tallest tower, a bridge for a toy car — and set a timer.</p><h2>3. Doctor visit</h2><p>Role play builds vocabulary and calms fears about real check-ups.</p><ul><li>Keep sessions short for toddlers.</li><li>Let the child lead.</li></ul>',
            'body_bn' => '<h2>১. ডো দিয়ে বেকারি</h2><p>মডেলিং ডো আর বেলুন দিয়ে খেলনার বেকারি সাজান।</p><h2>২. ব্রিকের চ্যালেঞ্জ</h2><p>সবচেয়ে উঁচু টাওয়ার বা খেলনা গাড়ির জন্য সেতু — টাইমার দিয়ে চ্যালেঞ্জ দিন।</p><h2>৩. ডাক্তার-ডাক্তার খেলা</h2><p>রোল প্লে শব্দভাণ্ডার বাড়ায় আর ডাক্তারের ভয় কমায়।</p>',
            'category' => 'parenting-tips', 'tags' => ['screen-free', 'crafts'], 'author' => 'tanvir-ahmed', 'cover' => 'TMB-PLA-RB10',
            'related' => ['play-doh-rainbow-starter-10-pack', 'lego-classic-creative-bricks-484', 'melissa-doug-wooden-doctor-kit'],
        ],
        'how-to-choose-safe-toys-for-babies' => [
            'type' => PostType::Article, 'status' => PostStatus::Published, 'days' => 33,
            'title' => 'How to choose safe toys for babies', 'title_bn' => 'শিশুর জন্য নিরাপদ খেলনা কীভাবে বেছে নেবেন',
            'excerpt' => 'Size, materials, batteries and age labels: a short checklist before you buy for a child under three.',
            'excerpt_bn' => 'আকার, উপাদান, ব্যাটারি আর বয়সের লেবেল: তিন বছরের কম বয়সী শিশুর জন্য কেনার আগে ছোট একটি চেকলিস্ট।',
            'body' => '<p>Babies explore with their mouths, so the first rule is size: anything that fits through a toilet-roll tube is a choking risk.</p><ul><li>Check the age label and respect it.</li><li>Prefer solid wood or BPA-free plastic.</li><li>Battery compartments must close with a screw.</li></ul>',
            'body_bn' => '<p>শিশুরা মুখ দিয়ে সবকিছু চেনে, তাই প্রথম নিয়ম আকার: টয়লেট-রোলের নলের ভেতর দিয়ে যা যায়, তা গলায় আটকাতে পারে।</p><ul><li>বয়সের লেবেল দেখুন ও মেনে চলুন।</li><li>শক্ত কাঠ বা BPA-মুক্ত প্লাস্টিক বেছে নিন।</li><li>ব্যাটারির ঢাকনা স্ক্রু দিয়ে বন্ধ হতে হবে।</li></ul>',
            'category' => 'toy-safety', 'tags' => ['safety', 'toddlers'], 'author' => 'sabina-yasmin', 'cover' => 'TMB-HUA-GYM',
            'related' => ['huanger-baby-activity-gym-play-mat', 'hape-pound-tap-bench-xylophone'],
        ],
        'eid-gift-ideas-under-2000' => [
            'type' => PostType::Article, 'status' => PostStatus::Published, 'days' => 24,
            'title' => 'Eid gift ideas under ৳2,000', 'title_bn' => '৳2,000-এর মধ্যে ঈদের উপহার',
            'excerpt' => 'Salami is lovely, but a toy lasts. Our favourite Eid gifts that stay under ৳2,000.',
            'excerpt_bn' => 'সালামি তো আছেই, কিন্তু খেলনা থেকে যায়। ৳2,000-এর মধ্যে আমাদের প্রিয় ঈদ উপহার।',
            'body' => '<p>For nieces, nephews and the neighbours\' children: gifts that delight without stretching the Eid budget.</p><p>Order by the 25th of Ramadan for delivery inside Dhaka before Chand Raat.</p>',
            'body_bn' => '<p>ভাতিজা-ভাতিজি আর প্রতিবেশীর বাচ্চাদের জন্য: ঈদের বাজেট না ছাড়িয়ে খুশি করার মতো উপহার।</p><p>চাঁদ রাতের আগে ঢাকায় ডেলিভারির জন্য ২৫ রমজানের মধ্যে অর্ডার করুন।</p>',
            'category' => 'gift-guides', 'tags' => ['gifts', 'eid'], 'author' => 'tanvir-ahmed', 'cover' => 'TMB-HOT-10PK',
            'related' => ['hot-wheels-10-car-gift-pack', 'rubiks-cube-3x3-original', 'mideer-wooden-shape-sorting-puzzle', 'crayola-washable-super-tips-20-markers'],
        ],
        'why-building-bricks-teach-stem' => [
            'type' => PostType::Article, 'status' => PostStatus::Published, 'days' => 15,
            'title' => 'Why building bricks are a quiet STEM lesson', 'title_bn' => 'বিল্ডিং ব্রিক কেন নিঃশব্দ স্টেম শিক্ষা',
            'excerpt' => 'Balance, symmetry, counting and planning: what really happens when a child builds a tower.',
            'excerpt_bn' => 'ভারসাম্য, প্রতিসাম্য, গোনা আর পরিকল্পনা: শিশু টাওয়ার বানালে আসলে কী ঘটে।',
            'body' => '<p>A tower that falls is an experiment. Children test balance, learn that a wide base holds more, and count studs without being asked.</p><blockquote>Free building first, instructions later.</blockquote>',
            'body_bn' => '<p>যে টাওয়ার ভেঙে পড়ে, সেটাও একটা পরীক্ষা। শিশু ভারসাম্য যাচাই করে, শেখে চওড়া ভিত বেশি ভার নেয়।</p><blockquote>আগে নিজের মতো বানানো, পরে নির্দেশনা।</blockquote>',
            'category' => 'play-and-learning', 'tags' => ['stem'], 'author' => 'sabina-yasmin', 'cover' => 'TMB-LEG-CL484',
            'related' => ['lego-classic-creative-bricks-484', 'clementoni-galileo-my-first-microscope'],
        ],
        'pohela-boishakh-crafts-with-kids' => [
            'type' => PostType::Article, 'status' => PostStatus::Published, 'days' => 6,
            'title' => 'Pohela Boishakh crafts to make with kids', 'title_bn' => 'শিশুদের সঙ্গে পহেলা বৈশাখের হাতের কাজ',
            'excerpt' => 'Paper masks, alpona patterns and a dough mela: festive crafts with what you already have.',
            'excerpt_bn' => 'কাগজের মুখোশ, আলপনার নকশা আর ডো দিয়ে মেলা: ঘরে থাকা জিনিস দিয়েই উৎসবের হাতের কাজ।',
            'body' => '<p>Washable markers make alpona patterns on paper plates; modelling dough becomes a whole village mela.</p>',
            'body_bn' => '<p>ধোয়া যায় এমন মার্কার দিয়ে কাগজের প্লেটে আলপনা আঁকুন; মডেলিং ডো দিয়ে বানিয়ে ফেলুন গোটা গ্রামের মেলা।</p>',
            'category' => 'parenting-tips', 'tags' => ['crafts', 'screen-free'], 'author' => 'tanvir-ahmed', 'cover' => 'TMB-CRA-ST20',
            'related' => ['crayola-washable-super-tips-20-markers', 'play-doh-rainbow-starter-10-pack'],
        ],
        'winter-outdoor-play-checklist' => [
            'type' => PostType::Article, 'status' => PostStatus::Draft, 'days' => null,
            'title' => 'Winter outdoor play checklist', 'title_bn' => 'শীতের বাইরের খেলার চেকলিস্ট',
            'excerpt' => 'Short days, cool mornings: how to keep children playing outside all winter.',
            'excerpt_bn' => null,
            'body' => '<p>Draft — photos and the Bangla version to follow.</p>',
            'body_bn' => null,
            'category' => 'parenting-tips', 'tags' => ['outdoor'], 'author' => 'tanvir-ahmed', 'cover' => 'TMB-LIT-SLIDE',
        ],
        'winter-holiday-gift-guide' => [
            'type' => PostType::Article, 'status' => PostStatus::Scheduled, 'days' => 5,
            'title' => 'Winter holiday gift guide', 'title_bn' => 'শীতের ছুটির উপহার গাইড',
            'excerpt' => 'Gifts for the long December holidays, by age and budget.',
            'excerpt_bn' => 'ডিসেম্বরের লম্বা ছুটির জন্য বয়স ও বাজেট অনুযায়ী উপহার।',
            'body' => '<p>School is out and cousins are visiting: our picks for the winter holidays.</p>',
            'body_bn' => '<p>স্কুল ছুটি, কাজিনরা বেড়াতে এসেছে: শীতের ছুটির জন্য আমাদের বাছাই।</p>',
            'category' => 'gift-guides', 'tags' => ['gifts', 'outdoor'], 'author' => 'tanvir-ahmed', 'cover' => 'TMB-BRU-MANDT',
            'related' => ['bruder-man-tga-dump-truck', 'little-tikes-first-slide', 'barbie-dreamhouse-pool-party-doll'],
        ],
    ];

    /** @var list<array{string, string, string, string, string, string|null, int, bool}> [name, name_bn, location, location_bn, quote, quote_bn, rating, published] */
    public const array TESTIMONIALS = [
        ['Nusrat Jahan', 'নুসরাত জাহান', 'Mirpur, Dhaka', 'মিরপুর, ঢাকা', 'Genuine LEGO at a fair price, and the delivery man called before coming. My go-to toy shop now.', 'ন্যায্য দামে আসল লেগো, আর ডেলিভারির আগে ফোন করেছিল। এখন এটাই আমার খেলনার দোকান।', 5, true],
        ['Tahmid Rahman', 'তাহমিদ রহমান', 'Agrabad, Chattogram', 'আগ্রাবাদ, চট্টগ্রাম', 'Cash on delivery and the box arrived sealed. The age filter made choosing a gift for my nephew easy.', 'ক্যাশ অন ডেলিভারি, আর বক্স সিল করা অবস্থায় পেয়েছি। বয়সের ফিল্টারে ভাতিজার উপহার বাছাই সহজ হয়েছে।', 5, true],
        ['Sadia Islam', 'সাদিয়া ইসলাম', 'Zindabazar, Sylhet', 'জিন্দাবাজার, সিলেট', 'They replaced a cracked puzzle piece without any argument. That is why I order again.', 'ভাঙা পাজলের টুকরো কোনো ঝামেলা ছাড়াই বদলে দিয়েছে। এজন্যই আবার অর্ডার করি।', 5, true],
        ['Arif Hossain', 'আরিফ হোসেন', 'Uttara, Dhaka', 'উত্তরা, ঢাকা', 'The buying guide helped me pick a first microscope my daughter actually uses.', 'বায়িং গাইড দেখে প্রথম মাইক্রোস্কোপ কিনেছি, মেয়ে সত্যিই ব্যবহার করে।', 4, true],
        ['Farzana Akter', 'ফারজানা আক্তার', 'Khulna', 'খুলনা', 'Delivery to Khulna took three days, but everything was well packed.', 'খুলনায় ডেলিভারি তিন দিন লেগেছে, তবে সব কিছু ভালোভাবে প্যাক করা ছিল।', 4, true],
        ['Rakibul Hasan', 'রাকিবুল হাসান', 'Rajshahi', 'রাজশাহী', 'Waiting for the owner to approve this one for the homepage.', null, 5, false],
    ];

    public function run(): void
    {
        $url = config('services.storefront.url');
        $secret = config('services.storefront.revalidate_secret');
        config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);

        try {
            Event::fakeFor(function (): void {
                $this->blog();
                $this->banners();
                $this->menus();
                $this->testimonials();
            }, [BannersChanged::class, MenusChanged::class, TestimonialsChanged::class, SeoPagesStale::class]);
        } finally {
            Carbon::setTestNow(null);
            CarbonImmutable::setTestNow(null);
            config(['services.storefront.url' => $url, 'services.storefront.revalidate_secret' => $secret]);
        }
    }

    private function blog(): void
    {
        $terms = app(SaveBlogTerm::class);
        $actor = self::actorId();
        $start = CarbonImmutable::now()->subDays(max(1, (int) config('demo.history_days', 60)) + 5);

        $categories = [];
        $position = 0;
        foreach (self::CATEGORIES as $slug => [$name, $nameBn, $description, $descriptionBn]) {
            $categories[$slug] = BlogCategory::query()->where('slug', $slug)->first()
                ?? self::at($start, fn () => $terms->handle(new BlogCategory, new BlogTermData($name, $nameBn, $slug, $description, $descriptionBn, $position), $actor));
            $position++;
        }

        $tags = [];
        foreach (self::TAGS as $slug => [$name, $nameBn]) {
            $tags[$slug] = BlogTag::query()->where('slug', $slug)->first()
                ?? self::at($start, fn () => $terms->handle(new BlogTag, new BlogTermData($name, $nameBn, $slug), $actor));
        }

        $editor = DemoStaffSeeder::user(Role::ContentEditor);
        $postActor = $editor === null ? $actor : $editor->id;
        $authors = [
            'tanvir-ahmed' => BlogAuthor::query()->where('slug', 'tanvir-ahmed')->first() ?? self::at($start, fn () => $terms->handle(new BlogAuthor, new BlogTermData(
                'Tanvir Ahmed', 'তানভীর আহমেদ', 'tanvir-ahmed',
                'Content editor at Toy Mart BD and father of two toy testers.', 'টয় মার্ট বিডির কনটেন্ট এডিটর, দুই খেলনা-পরীক্ষকের বাবা।',
                userId: $editor?->id,
            ), $actor)),
            'sabina-yasmin' => BlogAuthor::query()->where('slug', 'sabina-yasmin')->first() ?? self::at($start, fn () => $terms->handle(new BlogAuthor, new BlogTermData(
                'Sabina Yasmin', 'সাবিনা ইয়াসমিন', 'sabina-yasmin',
                'Early-childhood educator who writes our age guides.', 'প্রারম্ভিক শৈশব শিক্ষক, আমাদের বয়সভিত্তিক গাইড লেখেন।',
            ), $actor)),
        ];

        $posts = app(SaveBlogPost::class);
        $now = CarbonImmutable::now();
        foreach (self::POSTS as $slug => $p) {
            if (BlogPost::query()->where('slug', $slug)->exists()) {
                continue;
            }
            $days = $p['days'];
            $savedAt = match ($p['status']) {
                PostStatus::Published => $now->subDays((int) $days)->setTime(9, 30),
                default => $now->subDays(2)->setTime(16, 0),
            };
            $guide = $p['type'] === PostType::BuyingGuide;

            $data = new BlogPostData(
                type: $p['type'],
                title: $p['title'],
                titleBn: $p['title_bn'],
                slug: $slug,
                excerpt: $p['excerpt'],
                excerptBn: $p['excerpt_bn'],
                body: $p['body'],
                bodyBn: $p['body_bn'],
                coverMediaId: self::photo($p['cover']),
                authorId: $authors[$p['author']]->id,
                categoryId: $categories[$p['category']]->id,
                tagIds: array_map(static fn (string $t): int => $tags[$t]->id, $p['tags']),
                status: $p['status'],
                publishAt: $p['status'] === PostStatus::Scheduled ? $now->addDays((int) $days)->setTime(3, 0) : null,
                isFeatured: isset($p['featured']),
                guideAgeBand: null,
                guideBudgetMin: $guide ? Money::of('500.00') : null,
                guideBudgetMax: $guide ? Money::of('6000.00') : null,
                sections: $guide ? self::guideSections() : [],
                relatedProductIds: array_map(self::productId(...), $p['related'] ?? []),
                seo: ['title' => $p['title'].' | Toy Mart BD', 'title_bn' => $p['title_bn'].' | টয় মার্ট বিডি', 'description' => $p['excerpt'], 'description_bn' => $p['excerpt_bn']],
            );

            self::at($savedAt, fn (): BlogPost => $posts->handle(null, $data, $postActor));
        }
    }

    /** @return list<GuideSectionData> */
    private static function guideSections(): array
    {
        $pick = static fn (string $slug, string $note, string $noteBn): GuidePickData => new GuidePickData(self::productId($slug), $note, $noteBn);

        return [
            new GuideSectionData('0–12 months: senses and grasping', '০–১২ মাস: ইন্দ্রিয় ও ধরা',
                '<p>High contrast, soft textures and things to reach for.</p>', '<p>উজ্জ্বল রং, নরম স্পর্শ আর হাত বাড়িয়ে ধরার জিনিস।</p>',
                AgeBand::Infant, Money::of('1000.00'), Money::of('3500.00'), [
                    $pick('huanger-baby-activity-gym-play-mat', 'Tummy time with something to look at.', 'দেখার মতো কিছু নিয়ে টামি টাইম।'),
                    $pick('fisher-price-smart-stages-puppy', 'First words and counting, in songs.', 'গানে গানে প্রথম শব্দ আর গোনা।'),
                ]),
            new GuideSectionData('1–2 years: cause and effect', '১–২ বছর: কারণ ও ফলাফল',
                '<p>Bang it, sort it, push it — and watch what happens.</p>', '<p>ঠোকো, সাজাও, ঠেলো — আর দেখো কী হয়।</p>',
                AgeBand::Toddler, Money::of('1000.00'), Money::of('4500.00'), [
                    $pick('hape-pound-tap-bench-xylophone', 'Two toys in one, all solid wood.', 'একের ভেতর দুই, পুরোটাই শক্ত কাঠ।'),
                    $pick('mideer-wooden-shape-sorting-puzzle', 'Shapes big enough to be safe.', 'নিরাপদ হওয়ার মতো বড় আকার।'),
                    $pick('winfun-baby-walker-push-car', 'For the first wobbly steps.', 'প্রথম টলোমলো পায়ের জন্য।'),
                ]),
            new GuideSectionData('4–8 years: build and imagine', '৪–৮ বছর: বানাও ও কল্পনা করো',
                '<p>Open-ended building and role play.</p>', '<p>নিজের মতো বানানো আর রোল প্লে।</p>',
                AgeBand::Early, Money::of('800.00'), Money::of('5000.00'), [
                    $pick('lego-classic-creative-bricks-484', 'No instructions needed.', 'নির্দেশনা ছাড়াই খেলা যায়।'),
                    $pick('melissa-doug-wooden-doctor-kit', 'Role play that calms doctor fears.', 'ডাক্তারের ভয় কমায় এমন রোল প্লে।'),
                ]),
            new GuideSectionData('8+ years: ask why', '৮+ বছর: কেন জিজ্ঞেস করো',
                '<p>Puzzles and real science tools.</p>', '<p>পাজল আর সত্যিকারের বিজ্ঞানের যন্ত্র।</p>',
                AgeBand::Middle, Money::of('500.00'), Money::of('4000.00'), [
                    $pick('rubiks-cube-3x3-original', 'The classic brain teaser.', 'চিরচেনা ব্রেইন টিজার।'),
                    $pick('clementoni-galileo-my-first-microscope', 'A first real microscope.', 'প্রথম সত্যিকারের মাইক্রোস্কোপ।'),
                ]),
        ];
    }

    private function banners(): void
    {
        $save = app(SaveBanner::class);
        $actor = self::actorId();
        $now = CarbonImmutable::now();

        $banners = [
            new BannerData(BannerPlacement::AnnouncementBar, 'Free delivery over ৳3,000',
                'Free delivery on orders over ৳3,000', '৳3,000-এর বেশি অর্ডারে ফ্রি ডেলিভারি',
                linkUrl: '/categories/puzzles-games', linkLabel: 'Shop Puzzle week', linkLabelBn: 'পাজল সপ্তাহ দেখুন', priority: 10),
            new BannerData(BannerPlacement::InfoStrip, 'Same-day dispatch cut-off',
                'Order by 2 pm for same-day dispatch inside Dhaka', 'ঢাকার ভেতরে দুপুর ২টার মধ্যে অর্ডারে একই দিনে পাঠানো হয়',
                linkUrl: '/pages/delivery-policy', linkLabel: 'Delivery policy', linkLabelBn: 'ডেলিভারি নীতি'),
            new BannerData(BannerPlacement::CategoryPage, 'Baby & Toddler — BABY15',
                '15% off Baby & Toddler', 'শিশু ও বেবি খেলনায় ১৫% ছাড়',
                'Use code BABY15 on orders over ৳1,500.', '৳1,500-এর বেশি অর্ডারে BABY15 কোড ব্যবহার করুন।',
                mediaId: self::photo('TMB-HUA-GYM'), categoryId: self::categoryId('baby-toddler'), priority: 5,
                endsAt: $now->addDays(40)->setTimezone(DhakaDate::zone())->endOfDay()->utc()),
            new BannerData(BannerPlacement::Popup, 'Weekend flash sale popup',
                'Weekend flash sale is on', 'উইকেন্ড ফ্ল্যাশ সেল চলছে',
                'Four favourites at flash prices while stocks last.', 'স্টক থাকা পর্যন্ত চারটি প্রিয় খেলনা ফ্ল্যাশ দামে।',
                mediaId: self::photo('TMB-RUB-3X3'), linkUrl: '/products/rubiks-cube-3x3-original', linkLabel: 'See the deals', linkLabelBn: 'অফার দেখুন',
                startsAt: $now, endsAt: $now->addDays(3)->setTimezone(DhakaDate::zone())->endOfDay()->utc()),
            new BannerData(BannerPlacement::AnnouncementBar, 'Winter spend & save (scheduled)',
                'Winter spend & save: up to 8% off', 'শীতের কেনাকাটায় ৮% পর্যন্ত ছাড়',
                linkUrl: '/blog/winter-holiday-gift-guide', linkLabel: 'Gift ideas', linkLabelBn: 'উপহারের আইডিয়া', priority: 20,
                startsAt: $now->addDays(7)->setTimezone(DhakaDate::zone())->startOfDay()->utc(), endsAt: $now->addDays(21)->setTimezone(DhakaDate::zone())->endOfDay()->utc()),
        ];

        foreach ($banners as $data) {
            if (! Banner::query()->where('name', $data->name)->exists()) {
                $save->handle(null, $data, $actor);
            }
        }
    }

    private function menus(): void
    {
        $save = app(SaveMenu::class);
        $actor = self::actorId();
        $cat = static fn (string $slug, ?string $label = null): MenuItemData => self::link(MenuLinkType::Category, self::categoryId($slug), Category::query()->where('slug', $slug)->value('name'), Category::query()->where('slug', $slug)->value('name_bn'), $label);
        $brand = static fn (string $slug): MenuItemData => self::link(MenuLinkType::Brand, (int) Brand::query()->where('slug', $slug)->value('id'), Brand::query()->where('slug', $slug)->value('name'), Brand::query()->where('slug', $slug)->value('name_bn'));
        $page = static function (string $slug): MenuItemData {
            $p = CmsPage::query()->where('slug', $slug)->firstOrFail();

            return self::link(MenuLinkType::Page, $p->id, $p->title, $p->title_bn);
        };
        $guide = BlogPost::query()->where('slug', self::GUIDE_SLUG)->firstOrFail();

        $menus = [
            MenuLocation::Header->value => [
                new MenuItemData('Shop by category', 'ক্যাটাগরি অনুযায়ী', MenuLinkType::CategoryTree),
                new MenuItemData('Brands', 'ব্র্যান্ড', MenuLinkType::None, children: [
                    new MenuItemData('Top brands', 'জনপ্রিয় ব্র্যান্ড', MenuLinkType::None, children: [
                        $brand('lego'), $brand('fisher-price'), $brand('hot-wheels'), $brand('melissa-doug'), $brand('hape'),
                    ]),
                ]),
                $cat('baby-toddler'),
                $cat('science-learning', 'STEM'),
                self::link(MenuLinkType::Post, $guide->id, $guide->title, $guide->title_bn, 'Gift guide'),
                new MenuItemData('Blog', 'ব্লগ', MenuLinkType::Custom, url: '/blog'),
            ],
            MenuLocation::Footer->value => [
                new MenuItemData('Shop', 'কেনাকাটা', MenuLinkType::None, children: [
                    $cat('building-blocks'), $cat('baby-toddler'), $cat('puzzles-games'), $cat('art-craft'), $cat('science-learning'),
                ]),
                new MenuItemData('Help', 'সহায়তা', MenuLinkType::None, children: [
                    new MenuItemData('Track your order', 'অর্ডার ট্র্যাক করুন', MenuLinkType::Custom, url: '/track'),
                    $page('delivery-policy'), $page('returns-policy'), $page('faq'), $page('contact'),
                ]),
                new MenuItemData('Toy Mart BD', 'টয় মার্ট বিডি', MenuLinkType::None, children: [
                    $page('about'),
                    new MenuItemData('Blog', 'ব্লগ', MenuLinkType::Custom, url: '/blog'),
                    $page('privacy-policy'), $page('terms'),
                ]),
            ],
            MenuLocation::Mobile->value => [
                new MenuItemData('All categories', 'সব ক্যাটাগরি', MenuLinkType::CategoryTree),
                $cat('baby-toddler'),
                $cat('building-blocks'),
                $cat('outdoor-ride-on'),
                new MenuItemData('Track your order', 'অর্ডার ট্র্যাক করুন', MenuLinkType::Custom, url: '/track'),
                new MenuItemData('Blog', 'ব্লগ', MenuLinkType::Custom, url: '/blog'),
                $page('contact'),
            ],
        ];

        foreach ($menus as $location => $items) {
            $location = MenuLocation::from($location);
            if (! Menu::query()->where('location', $location->value)->exists()) {
                $save->handle($location, true, $items, $actor);
            }
        }
    }

    private function testimonials(): void
    {
        $save = app(SaveTestimonial::class);
        foreach (self::TESTIMONIALS as [$name, $nameBn, $location, $locationBn, $quote, $quoteBn, $rating, $published]) {
            if (! Testimonial::query()->where('name', $name)->exists()) {
                $save->handle(null, new TestimonialData($name, $quote, $nameBn, $location, $locationBn, $quoteBn, $rating, null, $published));
            }
        }
    }

    private static function link(MenuLinkType $type, int $id, mixed $name, mixed $nameBn, ?string $label = null): MenuItemData
    {
        return new MenuItemData($label ?? (string) $name, $label === null && is_string($nameBn) ? $nameBn : null, $type, $id);
    }

    /** The library photo of a demo product (by SKU), when the catalogue seeded its images. */
    private static function photo(string $sku): ?int
    {
        $filename = DemoCatalogSeeder::MEDIA_FILENAMES[$sku] ?? null;
        $id = $filename === null ? null : Media::query()->where('filename', $filename)->where('kind', 'image')->value('id');

        return $id === null ? null : (int) $id;
    }

    private static function productId(string $slug): int
    {
        return (int) (Product::query()->where('slug', $slug)->value('id') ?? throw new RuntimeException("Product {$slug} missing: run DemoCatalogSeeder first."));
    }

    private static function categoryId(string $slug): int
    {
        return (int) (Category::query()->where('slug', $slug)->value('id') ?? throw new RuntimeException("Category {$slug} missing: run CategoryDemoSeeder first."));
    }

    private static function actorId(): ?int
    {
        return (DemoStaffSeeder::user(Role::ContentEditor) ?? User::query()->where('email', 'owner@toymart.test')->first())?->id;
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
