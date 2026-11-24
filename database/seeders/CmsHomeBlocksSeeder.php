<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Media\Actions\UploadMedia;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Content\Blocks\Actions\CreateBlock;
use App\Domain\Content\Blocks\Actions\DeleteBlock;
use App\Domain\Content\Blocks\Actions\PublishLayout;
use App\Domain\Content\Blocks\DTOs\BlockSettings;
use App\Domain\Content\Blocks\Enums\BlockType;
use App\Domain\Content\Blocks\Enums\VideoLengthStatus;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Domain\Content\Blocks\Models\VideoLength;
use App\Domain\Content\Blocks\ValueObjects\LayoutKey;
use App\Domain\Content\Events\CmsLayoutPublished;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;

/**
 * The default homepage: the Smart Junior home (docs/_design/07-smart-junior-storefront.md §3.1,
 * §16.2; Vivid frames on Figma page 34:5658), one block per section in the design's order, with
 * the design's copy in both languages. Runs with the default seed (`php artisan db:seed`).
 *
 * Applied ONCE per site: when no published version carries SEED_NOTE yet, the homepage there now
 * (if any) is replaced and this one is published as the next version — the replaced one stays
 * restorable in Pages › Home › Versions. Once applied, re-seeding never touches the homepage
 * again, so the owner's later edits are safe. A new design revision bumps SEED_NOTE.
 *
 * Owner rule (§16.2): every word, link, image and figure on a section is block content. What the
 * design shows as a business fact — the parents' count and rating, the "why" figures, prices in
 * the promo cards, opening hours — is never seeded as a default anywhere: those are the design's
 * SAMPLE values, seeded only on a developer machine (self::sampleEnabled()) together with the
 * design's AI placeholder photos, the demo product picks and the sample reels. Elsewhere those
 * sections start empty or without the claim, and the owner fills them in Pages › Home.
 */
final class CmsHomeBlocksSeeder extends Seeder
{
    /** A CC0 clip from MDN, 5 seconds long; the sample reels play it. */
    public const string SAMPLE_VIDEO = 'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4';

    /** The version note this seed publishes under; its presence means "already applied here". */
    public const string SEED_NOTE = 'Smart Junior homepage (seeded)';

    public function run(CreateBlock $create, PublishLayout $publish, DeleteBlock $delete): void
    {
        $placement = LayoutKey::home();

        if (CmsLayoutVersion::query()->where('placement', $placement->value)->where('note', self::SEED_NOTE)->exists()) {
            return; // applied before: never overwrite what the owner has done since
        }

        $previous = CmsLayoutVersion::latestFor($placement)?->version;
        $replacing = $previous !== null || CmsBlock::query()->where('placement', $placement->value)->exists();
        if ($replacing) {
            foreach (CmsBlock::query()->inPlacement($placement)->get() as $block) {
                $delete->handle($block);
            }
            $this->command?->warn('Replacing the homepage with the Smart Junior home'.($previous !== null ? " (version {$previous} stays restorable in Pages › Home › Versions)" : '').'.');
        }

        if (self::sampleEnabled()) {
            CategoryDemoSeeder::dressExisting(); // the category tiles' art and short lines
        }

        foreach ($this->blocks(self::sampleEnabled()) as [$type, $content, $label]) {
            if ($content === null) {
                continue;
            }
            $schema = $type->schema();
            $create->handle($placement, $type, new BlockSettings(content: $schema->normalize($content), label: $label, present: ['label']), null, null);
        }

        // Replacing a live homepage is a real publish: the storefront's cached home is revalidated.
        if ($replacing) {
            $publish->handle($placement, null, self::SEED_NOTE);

            return;
        }

        // A first seed is not an editorial publish: no storefront revalidation is queued (a fresh
        // storefront has nothing cached, and seeding must not need STOREFRONT_URL or a queue).
        Event::fakeFor(static fn () => $publish->handle($placement, null, self::SEED_NOTE), [CmsLayoutPublished::class]);
    }

    /**
     * @return list<array{BlockType, array<string, mixed>|null, string}> null content = left out
     */
    private function blocks(bool $sample): array
    {
        $rail = BlockType::ProductRail->schema()->defaults();
        $photo = static fn (string $name, string $alt): ?int => self::samplePhoto('home/'.$name.'.webp', 'sj-home-'.$name.'.webp', $alt);

        return [
            [BlockType::Hero, [
                'show_age_pills' => ! $sample,
                'slides' => [[
                    'overline' => 'New season 2026', 'overline_bn' => 'নতুন সিজন 2026',
                    'headline' => 'Toys that grow with every age', 'headline_bn' => 'প্রতিটি বয়সের সাথে বেড়ে ওঠা খেলনা',
                    'accent' => 'every age', 'accent_bn' => 'খেলনা',
                    'sub' => 'Educational toys, soft toys and everyday essentials — picked with early-years educators, delivered across Bangladesh.',
                    'sub_bn' => 'শিক্ষামূলক খেলনা, সফট টয় আর প্রতিদিনের প্রয়োজনীয় জিনিস — শিশু-শিক্ষা বিশেষজ্ঞদের সাথে বাছাই করা, পৌঁছে দিই সারা বাংলাদেশে।',
                    'cta_label' => 'New arrivals', 'cta_label_bn' => 'নতুন এসেছে', 'cta_href' => '/toys?sort=newest',
                    'cta2_label' => 'Shop by age', 'cta2_label_bn' => 'বয়স অনুযায়ী কিনুন', 'cta2_href' => '/toys',
                    'media_id' => $photo('hero', 'Two children building with wooden blocks on a playroom rug'),
                ]],
                // Sample only: a discount and a price are promotions the owner runs, not defaults.
                'promos' => $sample ? [
                    ['overline' => 'Flash sale', 'overline_bn' => 'ফ্ল্যাশ সেল', 'title' => 'Up to 40% off soft toys', 'title_bn' => 'সফট টয়ে 40% পর্যন্ত ছাড়',
                        'cta_label' => 'Shop the sale', 'cta_label_bn' => 'সেল থেকে কিনুন', 'cta_href' => '/flash-sale',
                        'media_id' => $photo('hero-teddy', 'A soft teddy bear with a mint bow'), 'tone' => 'pink'],
                    ['overline' => 'STEM week', 'overline_bn' => 'STEM সপ্তাহ', 'title' => 'Science kits from ৳890', 'title_bn' => 'সায়েন্স কিট ৳890 থেকে',
                        'cta_label' => 'See the kits', 'cta_label_bn' => 'কিট দেখুন', 'cta_href' => '/toys',
                        'media_id' => $photo('hero-microscope', 'A children’s microscope kit'), 'tone' => 'green'],
                ] : [],
                'proof' => $sample ? [
                    'label' => '12,000+ parents’ choice', 'label_bn' => '12,000+ অভিভাবকের পছন্দ', 'rating' => 4.9,
                    'media_ids' => array_values(array_filter([$photo('avatar-1', ''), $photo('avatar-2', ''), $photo('avatar-3', '')])),
                ] : null,
            ], 'Hero'],

            [BlockType::UspBand, BlockType::UspBand->schema()->defaults(), 'Promise band'],

            [BlockType::CategoryTiles, [
                'eyebrow' => 'Categories', 'eyebrow_bn' => 'ক্যাটাগরি',
                'title' => 'Shop by category', 'title_bn' => 'ক্যাটাগরি অনুযায়ী কিনুন',
                'sub' => 'Find the right toy fast — every category sorted by what it teaches.',
                'sub_bn' => 'সঠিক খেলনা দ্রুত খুঁজুন — প্রতিটি ক্যাটাগরি শেখার মূল্য দেখে সাজানো।',
                'source' => 'top_level', 'limit' => 8, 'show_see_all' => false,
            ], 'Category tiles'],

            [BlockType::FlashSale, [
                'title' => "Today's flash deals", 'title_bn' => 'আজকের ফ্ল্যাশ ডিল',
                // Sample only: the 10 am drop is a sales routine the owner has not confirmed; without a
                // line the storefront states the running sale's end time.
                'sub' => $sample ? 'Prices drop every day at 10 am — while stocks last.' : null,
                'sub_bn' => $sample ? 'প্রতিদিন সকাল 10টায় দাম কমে — স্টক থাকা পর্যন্ত।' : null,
                'limit' => 8,
            ], 'Flash sale'],

            [BlockType::AgeTiles, [
                'eyebrow' => 'Age guide', 'eyebrow_bn' => 'বয়স গাইড', 'eyebrow_tone' => 'blue',
                'title' => 'Shop by age', 'title_bn' => 'বয়স অনুযায়ী কিনুন',
                'sub' => 'Every age plays differently. Start with your child’s age.',
                'sub_bn' => 'প্রতিটি বয়সের খেলা আলাদা। আপনার শিশুর বয়স দিয়ে শুরু করুন।',
                'show_see_all' => true,
                'cards' => [
                    ['title' => '0–2 years', 'title_bn' => '0–2 বছর', 'line' => 'Sensory & first play', 'line_bn' => 'সেন্সরি ও প্রথম খেলা',
                        'href' => '/toys?age=0-12,12-24', 'tone' => 'yellow', 'media_id' => $photo('baby', 'A smiling baby holding a soft ball')],
                    ['title' => '2–4 years', 'title_bn' => '2–4 বছর', 'line' => 'Stacking, sorting & pretend play', 'line_bn' => 'সাজানো, বাছাই, কল্পনার খেলা',
                        'href' => '/toys?age=24-48', 'tone' => 'pink', 'media_id' => $photo('girl-tower', 'A toddler stacking a block tower')],
                    ['title' => '4–7 years', 'title_bn' => '4–7 বছর', 'line' => 'Building, STEM & first reading', 'line_bn' => 'বানানো, STEM ও প্রথম পড়া',
                        'href' => '/toys?age=48-72,72-96', 'tone' => 'blue', 'media_id' => $photo('boy-tiles', 'A boy building with magnetic tiles')],
                    ['title' => '8+ years', 'title_bn' => '8+ বছর', 'line' => 'Art, science & strategy', 'line_bn' => 'আর্ট, বিজ্ঞান ও কৌশল',
                        'href' => '/toys?age=96-144,144-', 'tone' => 'green', 'media_id' => $photo('girl-painting', 'A girl painting with watercolours')],
                ],
            ], 'Shop by age'],

            [BlockType::ProductRail, [
                ...$rail,
                'eyebrow' => 'Most loved', 'eyebrow_bn' => 'সবচেয়ে প্রিয়', 'eyebrow_tone' => 'yellow',
                'title' => 'What parents buy again and again', 'title_bn' => 'অভিভাবকরা বারবার কেনেন যেগুলো',
                'sub' => 'The toys that get played with month after month.', 'sub_bn' => 'যে খেলনাগুলো মাসের পর মাস খেলা হয়।',
                'layout' => 'grid', 'see_all_label' => 'See all best sellers', 'see_all_label_bn' => 'সব বেস্টসেলার দেখুন',
                'chip_category_ids' => $this->categoryIds(['science-learning', 'dolls-soft-toys', 'puzzles-games', 'baby-toddler', 'art-craft']),
                'rule' => 'best_sellers',
            ], 'Best sellers'],

            [BlockType::PromoBanners, ['items' => [
                ['overline' => 'Gift shop', 'overline_bn' => 'গিফট শপ',
                    'title' => 'Birthday boxes, wrapped and ready', 'title_bn' => 'জন্মদিনের বক্স, র‍্যাপ করা ও প্রস্তুত',
                    // Sample only: a price and a free card are offers the owner has not confirmed.
                    'body' => $sample ? 'Curated gift combos from ৳990 — add a handwritten card for free.' : null,
                    'body_bn' => $sample ? 'বাছাই করা গিফট কম্বো ৳990 থেকে — হাতে লেখা কার্ড ফ্রি।' : null,
                    'cta_label' => 'Shop gift combos', 'cta_label_bn' => 'গিফট কম্বো কিনুন', 'cta_href' => '/toys',
                    'media_id' => $photo('promo-art-case', 'A lilac art case with crayons and paints'), 'tone' => 'purple'],
                ['overline' => 'Montessori corner', 'overline_bn' => 'মন্টেসরি কর্নার',
                    'title' => 'Calm, hands-on play for little learners', 'title_bn' => 'ছোট্ট শিক্ষার্থীদের জন্য শান্ত, হাতে-কলমে খেলা',
                    'body' => 'Wooden sorters, stackers and practical-life sets for ages 1–5.',
                    'body_bn' => '1–5 বছরের জন্য কাঠের সর্টার, স্ট্যাকার ও প্র্যাকটিক্যাল-লাইফ সেট।',
                    'cta_label' => 'Explore Montessori', 'cta_label_bn' => 'মন্টেসরি দেখুন', 'cta_href' => '/categories/puzzles-games',
                    'media_id' => $photo('promo-shape-board', 'A wooden Montessori shape board'), 'tone' => 'green'],
            ]], 'Promo banners'],

            [BlockType::ProductRail, [
                ...$rail,
                'eyebrow' => 'Just in', 'eyebrow_bn' => 'নতুন এসেছে', 'eyebrow_tone' => 'purple',
                'title' => 'New arrivals', 'title_bn' => 'সদ্য এসেছে',
                'sub' => 'This week’s new picks — be the first to play.', 'sub_bn' => 'এই সপ্তাহের নতুন বাছাই — প্রথম খেলুন আপনিই।',
                'layout' => 'slider', 'rule' => 'new', 'see_all_label' => null, 'see_all_label_bn' => null,
            ], 'New arrivals'],

            // A hand-picked rail: the picks are demo products, so it exists only where they do. The
            // owner adds it with a gift category or their own picks.
            [BlockType::ProductRail, ($gifts = $this->productIds(['lego-classic-creative-bricks-484', 'fisher-price-smart-stages-puppy', 'play-doh-rainbow-starter-10-pack', 'clementoni-galileo-my-first-microscope', 'melissa-doug-wooden-doctor-kit'])) === [] ? null : [
                ...$rail,
                'eyebrow' => 'Gift ideas', 'eyebrow_bn' => 'গিফট আইডিয়া', 'eyebrow_tone' => 'purple',
                'title' => 'Gifts & combo sets', 'title_bn' => 'উপহার ও কম্বো সেট',
                'sub' => 'The best gifts for a little one’s birthday or any occasion.', 'sub_bn' => 'জন্মদিন বা যেকোনো উপলক্ষে প্রিয় শিশুর জন্য সেরা উপহার',
                'layout' => 'slider', 'band' => 'pink', 'rule' => 'manual', 'product_ids' => $gifts, 'show_see_all' => false, 'see_all_label' => null, 'see_all_label_bn' => null,
            ], 'Gifts & combos'],

            [BlockType::WhyBand, [
                'eyebrow' => 'Why parents choose us', 'eyebrow_bn' => 'অভিভাবকরা কেন আমাদের বেছে নেন',
                'title' => 'Every toy is chosen for how it helps a child grow', 'title_bn' => 'প্রতিটি খেলনা বাছাই করা হয় শিশুর বেড়ে ওঠায় তার ভূমিকা দেখে',
                'body' => 'Our early-years team tests each product for safety, durability and real learning value before it reaches the shelf. If it does not earn its place in the playroom, we do not sell it.',
                'body_bn' => 'তাকে ওঠার আগে আমাদের শিশু-শিক্ষা টিম প্রতিটি পণ্যের নিরাপত্তা, টেকসই ভাব আর সত্যিকারের শেখার মূল্য যাচাই করে। খেলাঘরে জায়গা পাওয়ার যোগ্য না হলে আমরা তা বিক্রি করি না।',
                // Sample only: the owner types the real figures (WhyBandSchema never defaults one).
                'stats' => $sample ? [
                    ['value' => '50,000+', 'label' => 'happy families', 'label_bn' => 'সুখী পরিবার'],
                    ['value' => '4.9 / 5', 'label' => 'average rating', 'label_bn' => 'গড় রেটিং'],
                    ['value' => '64', 'label' => 'districts delivered', 'label_bn' => 'জেলায় ডেলিভারি'],
                    ['value' => '1,200+', 'label' => 'curated products', 'label_bn' => 'বাছাই করা পণ্য'],
                ] : [],
                'media_id' => $photo('why-boy', 'A boy building a house from magnetic tiles'),
            ], 'Why Smart Junior'],

            [BlockType::VideoReel, [
                'eyebrow' => 'Watch & play', 'eyebrow_bn' => 'দেখুন ও খেলুন', 'eyebrow_tone' => 'pink',
                'title' => 'Toys in action', 'title_bn' => 'খেলার মুহূর্ত',
                'sub' => 'Open the box, start playing — short reels of our toys.', 'sub_bn' => 'বাক্স খুলেই খেলা — আমাদের খেলনার ছোট ছোট রিল।',
                'band' => 'purple', 'see_all_label' => null, 'see_all_href' => null,
                // No videos outside a developer machine: the storefront leaves an empty reel out.
                'items' => $sample ? $this->sampleReels($photo) : [],
            ], 'Video reel'],

            [BlockType::BlogTeaser, [
                'eyebrow' => 'For parents', 'eyebrow_bn' => 'অভিভাবকদের জন্য', 'eyebrow_tone' => 'green',
                'title' => 'Play & learn journal', 'title_bn' => 'খেলো ও শেখো জার্নাল',
                'sub' => 'Age-by-age guides from our early-years team.', 'sub_bn' => 'আমাদের শিশু-শিক্ষা টিমের বয়সভিত্তিক গাইড।',
                'show_see_all' => false, 'limit' => 3,
            ], 'Journal'],

            [BlockType::ChatBand, [
                'title' => 'Not sure what to pick? Ask a play expert.', 'title_bn' => 'কী নেবেন বুঝতে পারছেন না? খেলা-বিশেষজ্ঞকে জিজ্ঞেস করুন।',
                // Sample only: the opening hours are the design's placeholder (UI-UX-DESIGN-SPEC §10).
                'body' => 'Tell us your child’s age and interests — we’ll suggest three toys within your budget.'.($sample ? ' Daily 9 am – 10 pm.' : ''),
                'body_bn' => 'শিশুর বয়স আর পছন্দ জানান — আপনার বাজেটে তিনটি খেলনা সাজেস্ট করব।'.($sample ? ' প্রতিদিন সকাল 9টা - রাত 10টা।' : ''),
                'button_label' => 'Chat on WhatsApp', 'button_label_bn' => 'WhatsApp-এ চ্যাট করুন',
                'tone' => 'yellow', 'show_hotline' => true,
            ], 'Help CTA'],
        ];
    }

    /**
     * Five reels on the sample clip, each with a design poster and a demo product chip. The clip's
     * length is recorded as measured, so seeding never fetches it (no network in a seed).
     *
     * @param  callable(string, string): ?int  $photo
     * @return list<array<string, mixed>>
     */
    private function sampleReels(callable $photo): array
    {
        VideoLength::query()->firstOrCreate(['url_hash' => VideoLength::hashOf(self::SAMPLE_VIDEO)], [
            'url' => self::SAMPLE_VIDEO, 'provider' => 'file', 'seconds' => 5, 'status' => VideoLengthStatus::Found, 'attempts' => 0, 'checked_at' => now(),
        ]);

        $reels = [
            ['A rainbow tower in one minute', 'এক মিনিটেই রংধনু টাওয়ার', '12.4k', 'girl-tower', 'A toddler stacking a block tower', 'mideer-wooden-shape-sorting-puzzle'],
            ['Every page reads aloud in Bangla', 'বাংলায় পড়ে শোনায় প্রতিটি পাতা', '8.1k', 'baby', 'A smiling baby holding a soft ball', 'lego-classic-creative-bricks-484'],
            ['Twenty houses later: our tile town', 'বিশটা বাড়ি পরে: আমাদের টাইল শহর', '21.7k', 'boy-tiles', 'A boy building with magnetic tiles', 'crayola-washable-super-tips-20-markers'],
            ['Unboxing a gift-wrapped delivery', 'গিফট-র‍্যাপ করা ডেলিভারির আনবক্সিং', '5.3k', 'girl-room', 'A girl unwrapping a gift in her playroom', 'melissa-doug-wooden-doctor-kit'],
            ['STEM night: our first volcano', 'STEM রাত: আমাদের প্রথম আগ্নেয়গিরি', '9.6k', 'girl-painting', 'A girl painting with watercolours', 'clementoni-galileo-my-first-microscope'],
        ];

        $items = [];
        foreach ($reels as [$title, $titleBn, $views, $poster, $alt, $product]) {
            $mediaId = $photo($poster, $alt);
            if ($mediaId === null) {
                continue; // a reel needs its poster
            }
            $items[] = [
                'title' => $title, 'title_bn' => $titleBn, 'views' => $views.' views', 'views_bn' => $views.' বার দেখা',
                'video_url' => self::SAMPLE_VIDEO, 'media_id' => $mediaId, 'duration' => null,
                'product_id' => $this->productIds([$product])[0] ?? null,
            ];
        }

        return $items;
    }

    /**
     * Sample content (the design's figures, AI placeholder photos, demo picks) only on a developer
     * machine: the photos are not licensed photography and the figures are not the business's.
     */
    public static function sampleEnabled(): bool
    {
        return app()->environment('local');
    }

    /**
     * The library id of `database/seeders/assets/{$asset}` (a mock photo from the Smart Junior
     * Figma file), uploaded the first time; null when sample content is off or the file is missing.
     * CategoryDemoSeeder uses it for the category art.
     */
    public static function samplePhoto(string $asset, string $filename, ?string $alt = null): ?int
    {
        if (! self::sampleEnabled()) {
            return null;
        }

        $id = Media::query()->where('filename', $filename)->where('kind', 'image')->value('id');
        if (is_numeric($id)) {
            return (int) $id;
        }

        $source = database_path('seeders/assets/'.$asset);
        if (! is_file($source)) {
            return null;
        }

        $copy = (string) tempnam(sys_get_temp_dir(), 'sample');
        copy($source, $copy);
        $previous = config('queue.default');
        config(['queue.default' => 'sync']); // renditions now, so the page has them

        try {
            return app(UploadMedia::class)->handle($copy, $filename, ['alt_text' => $alt === '' ? null : $alt])->id;
        } finally {
            config(['queue.default' => $previous]);
            @unlink($copy);
        }
    }

    /**
     * Ids of the categories that exist, in the order given.
     *
     * @param  list<string>  $slugs
     * @return list<int>
     */
    private function categoryIds(array $slugs): array
    {
        $ids = Category::query()->whereIn('slug', $slugs)->pluck('id', 'slug');

        return array_values(array_map('intval', array_filter(array_map(static fn (string $s) => $ids[$s] ?? null, $slugs))));
    }

    /**
     * Ids of the products that exist, in the order given.
     *
     * @param  list<string>  $slugs
     * @return list<int>
     */
    private function productIds(array $slugs): array
    {
        $ids = Product::query()->whereIn('slug', $slugs)->pluck('id', 'slug');

        return array_values(array_map('intval', array_filter(array_map(static fn (string $s) => $ids[$s] ?? null, $slugs))));
    }
}
