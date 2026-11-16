<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Media\Actions\UploadMedia;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Content\Blocks\Actions\CreateBlock;
use App\Domain\Content\Blocks\Actions\PublishLayout;
use App\Domain\Content\Blocks\DTOs\BlockSettings;
use App\Domain\Content\Blocks\Enums\BlockType;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Domain\Content\Blocks\ValueObjects\LayoutKey;
use App\Domain\Content\Enums\PageStatus;
use App\Domain\Content\Events\CmsLayoutPublished;
use App\Domain\Content\Models\CmsPage;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;

/**
 * Section content for the launch static pages (owner decision 2026-11-16: every page is built
 * from sections, like the homepage — CLAUDE.md rule 34a). Builds each page that StaticPagesSeeder
 * created out of the block library and publishes it as layout version 1:
 *
 * - about           — as designed (Figma 170:10502): story hero with the photo collage, promise
 *                     tiles, "What we believe" numbered cards and the visit band;
 * - contact         — as designed (Figma 165:9657): contact hero, channel tiles (values from
 *                     Settings › Store › Business), message form (→ Inbox) with visit card and links;
 * - delivery-policy — charges, times, cut-off, cash-on-delivery rules, and a delivery FAQ;
 * - returns-policy  — the 7-day window, conditions, how to return, refunds, and a FAQ;
 * - privacy-policy, terms — the policy text;
 * - faq             — four FAQ sections (ordering & payment, delivery, returns, toys & safety)
 *                     and the Contact details section.
 *
 * Numbers quote the seeded settings, not a guess: delivery ৳70 / ৳100 / ৳130 up to 1 kg and
 * ৳20 per extra kg (DeliverySeeder, Steadfast), free delivery from ৳3,000, a 5 PM cut-off, no
 * deliveries on Friday, 1–2 / 2–3 / 3–4 days, a ৳200 advance on cash-on-delivery orders above
 * ৳5,000 outside Dhaka (DeliverySettings::DEFAULTS), and a 7-day return window
 * (config/returns.php). Those are still PROVISIONAL business values (UI-UX-DESIGN-SPEC §10), so
 * if the owner changes a setting, the page copy must be edited to match. The policy, privacy and
 * terms wording is a drafted starting point, not legal advice: the owner reviews it before the
 * pages are published, which is why outside `local` the pages stay drafts (the owner previews
 * them from the page builder). On a developer machine they are also published so they render.
 *
 * Idempotent and never destructive: a page whose layout the owner has touched is left alone. The
 * only layout replaced is the one migration `2026_12_07_720000` made from StaticPagesSeeder's
 * PLACEHOLDER text (draft = a single placeholder Rich text section); its draft section is removed
 * and a new version is published on top (versions are append-only and never deleted).
 */
final class StaticPageSectionsSeeder extends Seeder
{
    public function __construct(private readonly CreateBlock $create, private readonly PublishLayout $publish) {}

    public function run(): void
    {
        foreach ($this->pages() as $slug => $build) {
            $page = CmsPage::query()->where('slug', $slug)->first();
            if ($page === null) {
                continue;
            }
            $key = LayoutKey::page($page->id);
            if (! $this->replaceable($key)) {
                continue;
            }

            // Built only now: a page the owner has edited never triggers its side effects (About's photo upload).
            $sections = $build();
            CmsBlock::query()->where('placement', $key->value)->delete();
            foreach ($sections as [$type, $label, $content, $settings]) {
                $schema = $type->schema();
                $this->create->handle($key, $type, new BlockSettings(
                    content: $schema->normalize([...$schema->defaults(), ...$content]),
                    label: $label,
                    showMobile: $settings['show_mobile'] ?? true,
                    showDesktop: $settings['show_desktop'] ?? true,
                    present: ['label', 'show_mobile', 'show_desktop'],
                ), null, null);
            }

            // Seeding is not an editorial publish: no storefront revalidation is queued.
            Event::fakeFor(fn () => $this->publish->handle($key, null, 'Default page content'), [CmsLayoutPublished::class]);

            if (app()->environment('local') && $page->status === PageStatus::Draft) {
                // A query update: seeding queues no storefront revalidation (nothing is cached yet).
                CmsPage::query()->whereKey($page->id)->update(['status' => PageStatus::Published, 'published_at' => now()->subMinute()]);
            }
        }
    }

    /** No layout yet, or only the placeholder one the migration made from StaticPagesSeeder's text. */
    private function replaceable(LayoutKey $key): bool
    {
        $blocks = CmsBlock::query()->where('placement', $key->value)->get();
        $live = CmsLayoutVersion::latestFor($key);
        if ($blocks->isEmpty() && $live === null) {
            return true;
        }

        $placeholder = static fn (string $type, mixed $content): bool => $type === BlockType::RichText->value
            && is_array($content) && str_contains((string) ($content['html'] ?? ''), 'PLACEHOLDER');

        $draftIsPlaceholder = $blocks->count() === 1 && $placeholder($blocks->first()->type->value, $blocks->first()->content);
        $liveBlocks = $live === null ? [] : $live->blocks;
        $liveIsPlaceholder = $live === null || (count($liveBlocks) === 1 && $placeholder((string) ($liveBlocks[0]['type'] ?? ''), $liveBlocks[0]['content'] ?? null));

        return ($blocks->isEmpty() || $draftIsPlaceholder) && $liveIsPlaceholder;
    }

    /**
     * slug → a builder of its ordered sections: [type, internal label, content (merged over the
     * type's defaults), settings].
     *
     * @return array<string, Closure(): list<array{BlockType, string, array<string, mixed>, array<string, bool>}>>
     */
    private function pages(): array
    {
        return [
            'about' => $this->about(...),
            'contact' => $this->contact(...),
            'delivery-policy' => $this->delivery(...),
            'returns-policy' => $this->returns(...),
            'privacy-policy' => $this->privacy(...),
            'terms' => $this->terms(...),
            'faq' => $this->faq(...),
        ];
    }

    /**
     * About us as designed (Figma 170:10502 / 174:8244): the story hero with its photo collage, the
     * four promise tiles, "What we believe" and the visit band. The shop address and hours in the
     * visit band are the design's PROVISIONAL values (UI-UX-DESIGN-SPEC §10) — the owner edits them.
     *
     * @return list<array{BlockType, string, array<string, mixed>, array<string, bool>}>
     */
    private function about(): array
    {
        return [
            [BlockType::StoryHero, 'Our story', [
                'overline' => 'Our story', 'overline_bn' => 'আমাদের গল্প',
                'title' => 'About Toy Mart BD', 'title_bn' => 'টয় মার্ট বিডি সম্পর্কে',
                'lead' => 'We started Toy Mart BD in Dhanmondi because buying a good toy in Bangladesh was harder than it should be: unclear age ranges, copies sold as originals, and no way to send anything back.',
                'lead_bn' => 'ধানমন্ডিতে টয় মার্ট বিডি শুরু করেছিলাম, কারণ বাংলাদেশে একটা ভালো খেলনা কেনা যতটা সহজ হওয়ার কথা, ততটা ছিল না: বয়সসীমা অস্পষ্ট, নকল জিনিস আসল বলে বিক্রি, আর ফেরত দেওয়ার কোনো উপায় নেই।',
                'body' => 'So we built the shop we wanted as parents: every toy checked for age and safety, honest prices, and a 7-day return if something is not right.',
                'body_bn' => 'তাই বাবা-মা হিসেবে যে দোকানটা চেয়েছিলাম, সেটাই বানিয়েছি: প্রতিটি খেলনা বয়স ও নিরাপত্তার জন্য যাচাই করা, সৎ দাম, আর কিছু ঠিক না থাকলে 7 দিনে ফেরত।',
                'media_ids' => $this->aboutPhotos(),
                'badge' => 'Ages', 'badge_bn' => 'বয়স', 'badge_sub' => '0–12', 'badge_sub_bn' => '0–12',
                'tone' => 'sunrise',
            ], []],
            [BlockType::FeatureTiles, 'Our promises', [
                'title' => null, 'title_bn' => null,
                'items' => [
                    ['icon' => 'shield_check', 'title' => 'Age-checked toys', 'title_bn' => 'বয়স যাচাই করা খেলনা',
                        'body' => 'Every toy is labelled with a real age range and choking-hazard warnings.', 'body_bn' => 'প্রতিটি খেলনায় আসল বয়সসীমা আর গলায় আটকানোর ঝুঁকির সতর্কতা লেখা থাকে।'],
                    ['icon' => 'check_circle', 'title' => '7-day returns', 'title_bn' => '7 দিনে ফেরত',
                        'body' => 'Wrong, broken or missing a part? Tell us within 7 days and we take it back.', 'body_bn' => 'ভুল, ভাঙা বা কোনো অংশ নেই? 7 দিনের মধ্যে জানান, আমরা ফেরত নেব।'],
                    ['icon' => 'gift', 'title' => 'Cash on delivery', 'title_bn' => 'ক্যাশ অন ডেলিভারি',
                        'body' => 'Pay when the parcel is in your hands, anywhere we deliver.', 'body_bn' => 'পার্সেল হাতে পেয়ে টাকা দিন, যেখানেই আমরা ডেলিভারি দিই।'],
                    ['icon' => 'map_pin', 'title' => 'Delivered nationwide', 'title_bn' => 'সারা দেশে ডেলিভারি',
                        'body' => 'Toys reach Dhaka addresses in 24 hours, and we deliver across Bangladesh.', 'body_bn' => 'ঢাকার ঠিকানায় 24 ঘণ্টায় খেলনা পৌঁছায়, আর সারা বাংলাদেশে ডেলিভারি দিই।'],
                ],
            ], []],
            [BlockType::NumberedCards, 'What we believe', [
                'title' => 'What we believe', 'title_bn' => 'আমরা যা বিশ্বাস করি',
                'items' => [
                    ['title' => 'Play is how children learn', 'title_bn' => 'খেলাই শিশুর শেখার পথ',
                        'body' => 'We pick toys that build hands, words and imagination, not just toys that flash.', 'body_bn' => 'আমরা এমন খেলনা বাছি যা হাত, ভাষা আর কল্পনা গড়ে — শুধু ঝলমলে খেলনা নয়।'],
                    ['title' => 'Parents deserve the truth', 'title_bn' => 'বাবা-মায়ের সত্যিটা জানার অধিকার আছে',
                        'body' => 'Real photos, real age ranges and prices that do not change at checkout.', 'body_bn' => 'আসল ছবি, আসল বয়সসীমা, আর চেকআউটে না বদলানো দাম।'],
                    ['title' => 'A real shop, real people', 'title_bn' => 'সত্যিকারের দোকান, সত্যিকারের মানুষ',
                        'body' => 'Visit us in Dhanmondi, or WhatsApp a person who knows the toys.', 'body_bn' => 'ধানমন্ডিতে চলে আসুন, অথবা খেলনা চেনেন এমন একজন মানুষকে হোয়াটসঅ্যাপ করুন।'],
                ],
            ], []],
            [BlockType::CtaBand, 'Visit our shop', [
                'title' => 'Come and play at our shop', 'title_bn' => 'আমাদের দোকানে খেলতে আসুন',
                'body' => 'House 12, Road 7, Dhanmondi, Dhaka · Saturday to Thursday, 10 am – 8 pm',
                'body_bn' => 'বাসা 12, রোড 7, ধানমন্ডি, ঢাকা · শনি থেকে বৃহস্পতি, সকাল 10টা – রাত 8টা',
                'primary_label' => 'Get directions', 'primary_label_bn' => 'পথ দেখুন', 'primary_href' => '/contact', 'primary_icon' => 'map_pin',
                'secondary_label' => 'Contact us', 'secondary_label_bn' => 'যোগাযোগ করুন', 'secondary_href' => '/contact', 'secondary_icon' => 'chat',
                'tone' => 'candy',
            ], []],
        ];
    }

    /**
     * The About collage photos. On a developer machine the design's mock photos (unbranded AI
     * images from the Figma file, toymartbd-app/storefront/public/mock/about) are added to the
     * media library once, so the page renders as designed; anywhere else the hero starts without
     * photos and the owner picks licensed ones in the builder.
     *
     * @return list<int>
     */
    private function aboutPhotos(): array
    {
        if (! app()->environment('local')) {
            return [];
        }

        $ids = [];
        foreach (['shape-sorter' => 'A shape-sorter puzzle', 'slide' => 'A child on a garden slide', 'blocks' => 'A child building with bricks'] as $name => $alt) {
            $filename = 'about-'.$name.'.jpg';
            $id = Media::query()->where('filename', $filename)->where('kind', 'image')->value('id');
            $source = base_path('toymartbd-app/storefront/public/mock/about/'.$name.'.jpg');
            if ($id === null && is_file($source)) {
                $copy = (string) tempnam(sys_get_temp_dir(), 'about');
                copy($source, $copy);
                $previous = config('queue.default');
                config(['queue.default' => 'sync']); // renditions now, so the page has them

                try {
                    $id = app(UploadMedia::class)->handle($copy, $filename, ['alt_text' => $alt])->id;
                } finally {
                    config(['queue.default' => $previous]);
                    @unlink($copy);
                }
            }
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Contact us as designed (Figma 165:9657 / 167:7500): the contact hero with its chat bubbles,
     * the four channel tiles (values from Settings › Store › Business), and the message form (→ the
     * admin Inbox) beside the visit card and quick links. The shop address and opening hours are
     * the design's PROVISIONAL values (UI-UX-DESIGN-SPEC §10) — the owner confirms or removes them
     * (an empty visit heading hides the card; the address switch hides the tile). No map link is
     * seeded: the address is not confirmed.
     *
     * @return list<array{BlockType, string, array<string, mixed>, array<string, bool>}>
     */
    private function contact(): array
    {
        return [
            [BlockType::ContactHero, "We're here to help", [
                'overline' => "We're here to help", 'overline_bn' => 'আমরা সাহায্য করতে আছি',
                'title' => 'Contact us', 'title_bn' => 'যোগাযোগ করুন',
                'lead' => 'Real people in our Dhaka shop answer every message, 9 am to 9 pm, Saturday to Thursday. Not sure which toy fits your child? Ask us. That is what we are here for.',
                'lead_bn' => 'আমাদের ঢাকার দোকানের সত্যিকারের মানুষেরা শনিবার থেকে বৃহস্পতিবার সকাল 9টা থেকে রাত 9টা পর্যন্ত প্রতিটি মেসেজের উত্তর দেন। কোন খেলনা আপনার সন্তানের জন্য ঠিক, বুঝতে পারছেন না? আমাদের জিজ্ঞেস করুন। এজন্যই আমরা আছি।',
                'status' => 'Online now · WhatsApp replies in about 15 minutes', 'status_bn' => 'এখন অনলাইনে · হোয়াটসঅ্যাপে প্রায় 15 মিনিটে উত্তর',
                'bubbles' => [
                    ['from' => 'customer', 'label' => 'Parent', 'label_bn' => 'অভিভাবক', 'text' => 'Is the shape sorter OK for a 2-year-old?', 'text_bn' => 'শেপ সর্টারটা কি 2 বছরের বাচ্চার জন্য ঠিক আছে?'],
                    ['from' => 'store', 'label' => 'Toy Mart', 'label_bn' => 'টয় মার্ট', 'text' => 'Yes. It is made for ages 1½ to 4, with chunky pieces too big to swallow.', 'text_bn' => 'হ্যাঁ। এটি দেড় থেকে 4 বছরের জন্য বানানো, টুকরোগুলো গিলে ফেলার মতো ছোট নয়।'],
                    ['from' => 'customer', 'label' => 'Parent', 'label_bn' => 'অভিভাবক', 'text' => 'Perfect, can it reach Mirpur by tomorrow?', 'text_bn' => 'চমৎকার, কাল মিরপুরে পৌঁছাবে?'],
                ],
            ], []],
            [BlockType::ContactInfo, 'Ways to reach us', [
                'title' => null, 'title_bn' => null, 'sub' => null, 'sub_bn' => null,
                'hours' => '9 am – 9 pm, Sat – Thu', 'hours_bn' => 'সকাল 9টা – রাত 9টা, শনি – বৃহস্পতি',
                'whatsapp_note' => 'Fastest: replies in minutes', 'whatsapp_note_bn' => 'সবচেয়ে দ্রুত: কয়েক মিনিটে উত্তর',
                'email_note' => 'Replies within 1 working day', 'email_note_bn' => '1 কর্মদিবসের মধ্যে উত্তর',
                'address_label' => 'Dhanmondi, Dhaka', 'address_label_bn' => 'ধানমন্ডি, ঢাকা',
                'address_note' => 'House 12, Road 7 · 10 am – 8 pm', 'address_note_bn' => 'বাসা 12, রোড 7 · সকাল 10টা – রাত 8টা',
                'show_messenger' => false,
            ], []],
            [BlockType::ContactForm, 'Send us a message', [
                'title' => 'Send us a message', 'title_bn' => 'আমাদের মেসেজ পাঠান',
                'intro' => 'Tell us what you need and we will call or WhatsApp you back. For an order, add the order number so we can find it straight away.',
                'intro_bn' => 'কী দরকার জানান, আমরা কল বা হোয়াটসঅ্যাপে আপনাকে উত্তর দেব। অর্ডারের বিষয়ে হলে অর্ডার নম্বরটি দিন, যাতে সঙ্গে সঙ্গে খুঁজে পাই।',
                'privacy' => 'We use your number only to reply to this message.', 'privacy_bn' => 'এই মেসেজের উত্তর দিতেই শুধু আপনার নম্বর ব্যবহার করি।',
                'visit_title' => 'Visit our shop', 'visit_title_bn' => 'আমাদের দোকানে আসুন',
                'visit_body' => 'House 12, Road 7, Dhanmondi, Dhaka 1205. Come and see the toys before you buy. Kids are welcome to play.',
                'visit_body_bn' => 'বাসা 12, রোড 7, ধানমন্ডি, ঢাকা 1205। কেনার আগে এসে খেলনা দেখে যান। বাচ্চারা এখানে খেলতে পারে।',
                'visit_map_href' => null,
                'hours' => [
                    ['label' => 'Saturday – Thursday', 'label_bn' => 'শনিবার – বৃহস্পতিবার', 'value' => '10 am – 8 pm', 'value_bn' => 'সকাল 10টা – রাত 8টা'],
                    ['label' => 'Friday', 'label_bn' => 'শুক্রবার', 'value' => 'Closed', 'value_bn' => 'বন্ধ'],
                    ['label' => 'Phone & WhatsApp', 'label_bn' => 'ফোন ও হোয়াটসঅ্যাপ', 'value' => '9 am – 9 pm', 'value_bn' => 'সকাল 9টা – রাত 9টা'],
                ],
                'links_title' => 'Quicker than a message', 'links_title_bn' => 'মেসেজের চেয়েও দ্রুত',
                'links' => [
                    ['icon' => 'check_circle', 'label' => 'Track your order', 'label_bn' => 'অর্ডার ট্র্যাক করুন', 'href' => '/track'],
                    ['icon' => 'clock', 'label' => 'Delivery charges and times', 'label_bn' => 'ডেলিভারি চার্জ ও সময়', 'href' => '/delivery-policy'],
                    ['icon' => 'shield_check', 'label' => 'Returns and refunds', 'label_bn' => 'রিটার্ন ও টাকা ফেরত', 'href' => '/returns-policy'],
                    ['icon' => 'chat', 'label' => 'Common questions', 'label_bn' => 'সাধারণ প্রশ্ন', 'href' => '/faq'],
                ],
            ], []],
        ];
    }

    /** @return list<array{BlockType, string, array<string, mixed>, array<string, bool>}> */
    private function delivery(): array
    {
        return [
            [BlockType::RichText, 'Delivery policy', [
                'html' => '<p>We deliver to every district in Bangladesh through our courier partners. You pay the delivery charge together with your order — in cash on delivery, or online where offered at checkout.</p>'
                    .'<h2>Delivery charges</h2>'
                    .'<ul><li><strong>Inside Dhaka city:</strong> ৳70</li>'
                    .'<li><strong>Dhaka suburbs</strong> (Gazipur, Narayanganj, Savar, Keraniganj and nearby): ৳100</li>'
                    .'<li><strong>Outside Dhaka:</strong> ৳130</li></ul>'
                    .'<p>These cover parcels up to 1 kg. Each extra kilogram (or part of one) adds ৳20 — large boxed toys such as ride-ons and play kitchens weigh more, and checkout always shows the exact charge before you confirm.</p>'
                    .'<p><strong>Free delivery</strong> on orders of ৳3,000 or more.</p>'
                    .'<h2>How long it takes</h2>'
                    .'<ul><li>Inside Dhaka city: 1–2 working days</li><li>Dhaka suburbs: 2–3 working days</li><li>Outside Dhaka: 3–4 working days</li></ul>'
                    .'<p>Orders confirmed before 5 PM are handed to the courier the same day. Couriers do not deliver on Fridays or public holidays, and delivery can take longer around Eid and during strikes or floods — we will keep you updated.</p>'
                    .'<h2>Cash on delivery</h2>'
                    .'<p>Cash on delivery is available everywhere we deliver. For cash-on-delivery orders above ৳5,000 outside Dhaka we ask for a ৳200 advance to confirm the order; it is deducted from the amount you pay the rider.</p>'
                    .'<p>We may call to confirm a new order before we send it. If we cannot reach you, we hold the order — we never cancel it without talking to you.</p>'
                    .'<h2>Receiving your parcel</h2>'
                    .'<ul><li>Please check the parcel in front of the rider before you pay. If the box is damaged, a toy is missing or it is not what you ordered, do not accept it — call us and we will put it right.</li>'
                    .'<li>Keep your phone on: the rider calls before arriving. A clear landmark in your address helps a lot.</li>'
                    .'<li>A few remote areas are not served by our couriers yet. Checkout tells you if your area is one of them.</li></ul>',
                'html_bn' => '<p>আমাদের কুরিয়ার সহযোগীদের মাধ্যমে বাংলাদেশের প্রতিটি জেলায় ডেলিভারি দিই। ডেলিভারি চার্জ অর্ডারের সঙ্গেই দেন — ক্যাশ অন ডেলিভারিতে, অথবা চেকআউটে অনলাইন পেমেন্টের সুযোগ থাকলে অনলাইনে।</p>'
                    .'<h2>ডেলিভারি চার্জ</h2>'
                    .'<ul><li><strong>ঢাকা শহরের ভেতরে:</strong> ৳70</li>'
                    .'<li><strong>ঢাকার আশপাশ</strong> (গাজীপুর, নারায়ণগঞ্জ, সাভার, কেরানীগঞ্জ ও কাছাকাছি): ৳100</li>'
                    .'<li><strong>ঢাকার বাইরে:</strong> ৳130</li></ul>'
                    .'<p>এই চার্জ 1 কেজি পর্যন্ত পার্সেলের জন্য। প্রতি অতিরিক্ত কেজিতে (বা তার অংশে) ৳20 যোগ হয় — রাইড-অন বা কিচেন সেটের মতো বড় বাক্সের ওজন বেশি, আর নিশ্চিত করার আগে চেকআউটে সবসময় সঠিক চার্জ দেখায়।</p>'
                    .'<p>৳3,000 বা তার বেশি অর্ডারে <strong>ফ্রি ডেলিভারি</strong>।</p>'
                    .'<h2>কত দিন লাগে</h2>'
                    .'<ul><li>ঢাকা শহরের ভেতরে: 1–2 কর্মদিবস</li><li>ঢাকার আশপাশ: 2–3 কর্মদিবস</li><li>ঢাকার বাইরে: 3–4 কর্মদিবস</li></ul>'
                    .'<p>বিকেল 5টার আগে নিশ্চিত হওয়া অর্ডার সেদিনই কুরিয়ারে দেওয়া হয়। শুক্রবার ও সরকারি ছুটির দিনে কুরিয়ার ডেলিভারি দেয় না, আর ঈদের সময়, হরতাল বা বন্যায় দেরি হতে পারে — আমরা আপনাকে জানিয়ে রাখব।</p>'
                    .'<h2>ক্যাশ অন ডেলিভারি</h2>'
                    .'<p>আমরা যেখানে ডেলিভারি দিই সব জায়গায় ক্যাশ অন ডেলিভারি আছে। ঢাকার বাইরে ৳5,000-এর বেশি ক্যাশ অন ডেলিভারি অর্ডারে নিশ্চিত করতে ৳200 অগ্রিম নিই; রাইডারকে দেওয়া টাকা থেকে তা বাদ যায়।</p>'
                    .'<p>নতুন অর্ডার পাঠানোর আগে আমরা ফোন করে নিশ্চিত করতে পারি। আপনাকে না পেলে অর্ডারটি রেখে দিই — আপনার সঙ্গে কথা না বলে কখনো বাতিল করি না।</p>'
                    .'<h2>পার্সেল গ্রহণ</h2>'
                    .'<ul><li>টাকা দেওয়ার আগে রাইডারের সামনে পার্সেল দেখে নিন। বাক্স ক্ষতিগ্রস্ত হলে, কোনো খেলনা না থাকলে বা অর্ডারের সঙ্গে না মিললে গ্রহণ করবেন না — আমাদের ফোন করুন, আমরা ঠিক করে দেব।</li>'
                    .'<li>ফোন খোলা রাখুন: পৌঁছানোর আগে রাইডার ফোন করবেন। ঠিকানায় স্পষ্ট ল্যান্ডমার্ক দিলে অনেক সুবিধা হয়।</li>'
                    .'<li>কিছু প্রত্যন্ত এলাকায় আমাদের কুরিয়ার এখনো যায় না। আপনার এলাকা তার মধ্যে হলে চেকআউটে জানিয়ে দেবে।</li></ul>',
            ], []],
            [BlockType::Faq, 'Delivery questions', [
                'title' => 'Delivery questions', 'title_bn' => 'ডেলিভারি নিয়ে প্রশ্ন',
                'items' => [
                    $this->qa('Can I choose a delivery time?', 'Couriers deliver during the day and cannot promise an exact time, but the rider calls before arriving. Add a note at checkout if someone else will receive the parcel.',
                        'ডেলিভারির সময় বেছে নেওয়া যাবে?', 'কুরিয়ার দিনের বেলায় ডেলিভারি দেয়, নির্দিষ্ট সময়ের নিশ্চয়তা দিতে পারে না, তবে পৌঁছানোর আগে রাইডার ফোন করেন। অন্য কেউ পার্সেল নিলে চেকআউটে নোট দিন।'),
                    $this->qa('Can I change my address after ordering?', 'Yes, until the order is handed to the courier. Call or WhatsApp us with your order number as soon as possible.',
                        'অর্ডারের পর ঠিকানা বদলানো যাবে?', 'হ্যাঁ, কুরিয়ারে দেওয়ার আগ পর্যন্ত। যত দ্রুত সম্ভব অর্ডার নম্বরসহ কল বা হোয়াটসঅ্যাপ করুন।'),
                    $this->qa('What if I am not home when the rider comes?', 'The rider will call and usually try again the next working day. If the parcel cannot be delivered after repeated attempts it comes back to us, and we will call you to arrange it again.',
                        'রাইডার এলে বাসায় না থাকলে?', 'রাইডার ফোন করবেন এবং সাধারণত পরের কর্মদিবসে আবার চেষ্টা করবেন। বারবার চেষ্টায়ও ডেলিভারি না হলে পার্সেল আমাদের কাছে ফেরত আসে, আর আমরা ফোন করে আবার ব্যবস্থা করব।'),
                    $this->qa('Do you deliver gifts to another address?', 'Yes. Enter the recipient\'s name, phone and address at checkout. Add a note at checkout if it is a gift.',
                        'অন্য ঠিকানায় উপহার পাঠানো যায়?', 'হ্যাঁ। চেকআউটে প্রাপকের নাম, ফোন ও ঠিকানা দিন। উপহার হলে চেকআউটে নোট দিন।'),
                ],
            ], []],
        ];
    }

    /** @return list<array{BlockType, string, array<string, mixed>, array<string, bool>}> */
    private function returns(): array
    {
        return [
            [BlockType::RichText, 'Returns policy', [
                'html' => '<p>We want every toy to bring a smile. If something is not right, tell us within <strong>7 days of delivery</strong> and we will fix it.</p>'
                    .'<h2>What you can return</h2>'
                    .'<ul><li><strong>Damaged, faulty or wrong items</strong> — always. We collect it and send the right item, or refund you, at no cost to you.</li>'
                    .'<li><strong>Change of mind</strong> — if the toy is unused and in its original, unopened packaging with all tags and parts.</li></ul>'
                    .'<h2>What we cannot take back</h2>'
                    .'<ul><li>Opened art and craft supplies, modelling dough and slime</li><li>Bath toys, teethers and other items that go in a baby\'s mouth, once unsealed</li><li>Toys that have been used, assembled or damaged after delivery</li></ul>'
                    .'<h2>How to return</h2>'
                    .'<ol><li>Call or WhatsApp us within 7 days of delivery with your order number and a photo of the item.</li><li>We confirm the return and arrange a pickup, or tell you how to send it.</li><li>Once the item reaches us and passes a check, we send the replacement or the refund.</li></ol>'
                    .'<h2>Refunds</h2>'
                    .'<p>Refunds are made within 7 working days of the item reaching us — to the bKash or Nagad number you give us, or to your bank account. Online payments can also be refunded to the original method. For a change-of-mind return the delivery charge is not refunded, and the return delivery is at your cost.</p>'
                    .'<p>If you refused a damaged or wrong parcel at the door, you have paid nothing for it — just call us and we will send the right one.</p>',
                'html_bn' => '<p>আমরা চাই প্রতিটি খেলনা হাসি আনুক। কিছু ঠিক না থাকলে <strong>ডেলিভারির 7 দিনের মধ্যে</strong> জানান, আমরা ঠিক করে দেব।</p>'
                    .'<h2>কী ফেরত দেওয়া যায়</h2>'
                    .'<ul><li><strong>ক্ষতিগ্রস্ত, ত্রুটিপূর্ণ বা ভুল পণ্য</strong> — সবসময়। আমরা সংগ্রহ করে সঠিক পণ্য পাঠাই, বা টাকা ফেরত দিই, আপনার কোনো খরচ ছাড়া।</li>'
                    .'<li><strong>মত বদলালে</strong> — খেলনা অব্যবহৃত, আসল প্যাকেট না খোলা এবং সব ট্যাগ ও অংশসহ থাকলে।</li></ul>'
                    .'<h2>যা ফেরত নেওয়া যায় না</h2>'
                    .'<ul><li>খোলা আঁকা ও হাতের কাজের সামগ্রী, মডেলিং ডো ও স্লাইম</li><li>সিল খোলার পর বাথ টয়, টিদার ও শিশুর মুখে যায় এমন জিনিস</li><li>ডেলিভারির পর ব্যবহৃত, জোড়া লাগানো বা ক্ষতিগ্রস্ত খেলনা</li></ul>'
                    .'<h2>কীভাবে ফেরত দেবেন</h2>'
                    .'<ol><li>ডেলিভারির 7 দিনের মধ্যে অর্ডার নম্বর ও পণ্যের ছবিসহ কল বা হোয়াটসঅ্যাপ করুন।</li><li>আমরা রিটার্ন নিশ্চিত করে পিকআপের ব্যবস্থা করি, বা কীভাবে পাঠাবেন জানাই।</li><li>পণ্য আমাদের কাছে পৌঁছে যাচাই শেষ হলে বদলি পণ্য বা টাকা পাঠাই।</li></ol>'
                    .'<h2>টাকা ফেরত</h2>'
                    .'<p>পণ্য আমাদের কাছে পৌঁছানোর 7 কর্মদিবসের মধ্যে টাকা ফেরত দিই — আপনার দেওয়া বিকাশ বা নগদ নম্বরে, বা ব্যাংক অ্যাকাউন্টে। অনলাইন পেমেন্ট আগের মাধ্যমেও ফেরত দেওয়া যায়। মত বদলানোর রিটার্নে ডেলিভারি চার্জ ফেরত হয় না, আর ফেরত পাঠানোর খরচ আপনার।</p>'
                    .'<p>দরজায় ক্ষতিগ্রস্ত বা ভুল পার্সেল ফিরিয়ে দিলে আপনি কোনো টাকা দেননি — শুধু ফোন করুন, আমরা সঠিকটি পাঠাব।</p>',
            ], []],
            [BlockType::Faq, 'Returns questions', [
                'title' => 'Returns questions', 'title_bn' => 'রিটার্ন নিয়ে প্রশ্ন',
                'items' => [
                    $this->qa('Can I exchange a toy for a different one?', 'Yes. An exchange follows the same rules as a return: tell us within 7 days, and the toy must be unused in its unopened box. If the new toy costs more, you pay the difference; if less, we refund it.',
                        'একটা খেলনা বদলে অন্যটা নেওয়া যাবে?', 'হ্যাঁ। বদলানোর নিয়ম রিটার্নের মতোই: 7 দিনের মধ্যে জানান, আর খেলনা অব্যবহৃত ও বাক্স না খোলা থাকতে হবে। নতুনটার দাম বেশি হলে পার্থক্য দেবেন; কম হলে আমরা ফেরত দেব।'),
                    $this->qa('A part is missing from the box. What do I do?', 'Send us a photo of the contents and the box within 7 days. We send the missing part or a complete replacement, free.',
                        'বাক্সে একটা অংশ নেই। কী করব?', '7 দিনের মধ্যে বাক্স ও ভেতরের জিনিসের ছবি পাঠান। আমরা বাদ পড়া অংশ বা পুরো নতুন সেট বিনা খরচে পাঠাব।'),
                    $this->qa('Is there a warranty on electronic toys?', 'Where the brand offers a warranty, the product page says so and we handle the claim for you. Keep the box and the invoice.',
                        'ইলেকট্রনিক খেলনায় ওয়ারেন্টি আছে?', 'ব্র্যান্ড ওয়ারেন্টি দিলে পণ্যের পাতায় লেখা থাকে, আর আমরা আপনার হয়ে দাবি সামলাই। বাক্স ও ইনভয়েস রেখে দিন।'),
                ],
            ], []],
        ];
    }

    /** @return list<array{BlockType, string, array<string, mixed>, array<string, bool>}> */
    private function privacy(): array
    {
        return [[BlockType::RichText, 'Privacy policy', [
            'html' => '<p>This policy explains what Toy Mart BD collects when you shop with us, why, and the choices you have. We collect as little as we need to deliver your order and look after you.</p>'
                .'<h2>What we collect</h2>'
                .'<ul><li><strong>Order details:</strong> your name, phone number, delivery address and landmark, and what you bought.</li>'
                .'<li><strong>Account details</strong>, if you create one: your email address and saved addresses.</li>'
                .'<li><strong>Messages</strong> you send us by phone, WhatsApp, Messenger or email.</li>'
                .'<li><strong>Site usage:</strong> pages viewed and the device and browser you use, to keep the site fast and working. We do not collect card numbers — online payments are handled by the payment provider.</li></ul>'
                .'<h2>How we use it</h2>'
                .'<ul><li>To confirm, pack and deliver your order, and to contact you about it.</li><li>To handle returns, refunds and warranty claims.</li><li>To prevent fraud and fake orders, which keeps cash on delivery possible for everyone.</li><li>To send offers — only if you agree, and you can stop them at any time.</li></ul>'
                .'<h2>Who we share it with</h2>'
                .'<p>Only with the people who need it to serve you: our courier partners (your name, phone and address, to deliver), payment providers (to process a payment), and SMS and WhatsApp providers (to send order updates). We never sell your information.</p>'
                .'<h2>How long we keep it</h2>'
                .'<p>Order records are kept as long as the law requires for accounts and tax. You can ask us to delete your account and marketing data at any time.</p>'
                .'<h2>Your choices</h2>'
                .'<p>You can ask to see, correct or delete the information we hold about you, or to stop marketing messages, by contacting us. We answer within 30 days.</p>'
                .'<p>We may update this policy; the latest version is always on this page.</p>',
            'html_bn' => '<p>আমাদের থেকে কেনাকাটার সময় টয় মার্ট বিডি কী তথ্য সংগ্রহ করে, কেন করে, আর আপনার কী কী সুযোগ আছে — এই নীতিতে তা বলা হলো। অর্ডার পৌঁছাতে ও আপনার যত্ন নিতে যতটুকু দরকার, তার বেশি আমরা সংগ্রহ করি না।</p>'
                .'<h2>আমরা যা সংগ্রহ করি</h2>'
                .'<ul><li><strong>অর্ডারের তথ্য:</strong> আপনার নাম, ফোন নম্বর, ডেলিভারির ঠিকানা ও ল্যান্ডমার্ক, আর কী কিনেছেন।</li>'
                .'<li><strong>অ্যাকাউন্টের তথ্য</strong>, অ্যাকাউন্ট খুললে: ইমেইল ঠিকানা ও সংরক্ষিত ঠিকানা।</li>'
                .'<li><strong>মেসেজ</strong> যা আপনি ফোন, হোয়াটসঅ্যাপ, মেসেঞ্জার বা ইমেইলে পাঠান।</li>'
                .'<li><strong>সাইট ব্যবহার:</strong> দেখা পাতা এবং আপনার ডিভাইস ও ব্রাউজার, সাইট দ্রুত ও সচল রাখতে। আমরা কার্ড নম্বর সংগ্রহ করি না — অনলাইন পেমেন্ট পেমেন্ট প্রদানকারী সামলায়।</li></ul>'
                .'<h2>আমরা যেভাবে ব্যবহার করি</h2>'
                .'<ul><li>অর্ডার নিশ্চিত, প্যাক ও ডেলিভারি করতে, এবং এ বিষয়ে আপনার সঙ্গে যোগাযোগ করতে।</li><li>রিটার্ন, টাকা ফেরত ও ওয়ারেন্টি দাবি সামলাতে।</li><li>প্রতারণা ও ভুয়া অর্ডার ঠেকাতে, যাতে সবার জন্য ক্যাশ অন ডেলিভারি চালু রাখা যায়।</li><li>অফার পাঠাতে — শুধু আপনি রাজি হলে, আর যেকোনো সময় বন্ধ করতে পারবেন।</li></ul>'
                .'<h2>আমরা যাদের সঙ্গে শেয়ার করি</h2>'
                .'<p>শুধু যাদের আপনাকে সেবা দিতে দরকার: কুরিয়ার সহযোগী (ডেলিভারির জন্য নাম, ফোন ও ঠিকানা), পেমেন্ট প্রদানকারী (পেমেন্টের জন্য), এবং এসএমএস ও হোয়াটসঅ্যাপ প্রদানকারী (অর্ডারের আপডেট পাঠাতে)। আমরা কখনো আপনার তথ্য বিক্রি করি না।</p>'
                .'<h2>কত দিন রাখি</h2>'
                .'<p>হিসাব ও করের জন্য আইন যত দিন চায়, অর্ডারের রেকর্ড তত দিন রাখা হয়। যেকোনো সময় আপনার অ্যাকাউন্ট ও মার্কেটিং তথ্য মুছে দিতে বলতে পারেন।</p>'
                .'<h2>আপনার সুযোগ</h2>'
                .'<p>আমাদের সঙ্গে যোগাযোগ করে আপনার সম্পর্কে রাখা তথ্য দেখতে, সংশোধন করতে বা মুছতে, অথবা মার্কেটিং মেসেজ বন্ধ করতে বলতে পারেন। আমরা 30 দিনের মধ্যে উত্তর দিই।</p>'
                .'<p>আমরা এই নীতি হালনাগাদ করতে পারি; সর্বশেষ সংস্করণ সবসময় এই পাতায় থাকে।</p>',
        ], []]];
    }

    /** @return list<array{BlockType, string, array<string, mixed>, array<string, bool>}> */
    private function terms(): array
    {
        return [[BlockType::RichText, 'Terms and conditions', [
            'html' => '<p>These terms apply when you browse or buy from Toy Mart BD. By placing an order you agree to them.</p>'
                .'<h2>Orders</h2>'
                .'<ul><li>An order is confirmed when we accept it, which may include a call to check the details. We may decline or hold an order we cannot verify; we never cancel without telling you.</li>'
                .'<li>We do our best to show every product accurately. Colours on screen can differ slightly from the real toy.</li>'
                .'<li>If an item turns out to be out of stock after you order, we tell you and refund anything you paid for it.</li></ul>'
                .'<h2>Prices and payment</h2>'
                .'<ul><li>Prices are in Bangladeshi taka (৳) and include VAT where it applies. The delivery charge is shown at checkout before you confirm.</li>'
                .'<li>If a price on the site is clearly wrong, we will contact you before sending the order — you may cancel or pay the correct price.</li>'
                .'<li>You can pay cash on delivery or online where offered. Coupons and offers cannot be exchanged for cash and may have their own conditions.</li></ul>'
                .'<h2>Delivery, returns and refunds</h2>'
                .'<p>Delivery is covered by our <a href="/delivery-policy">delivery policy</a> and returns by our <a href="/returns-policy">returns policy</a>, which form part of these terms.</p>'
                .'<h2>Safety</h2>'
                .'<p>Please follow the age label and the maker\'s instructions on every toy, and supervise young children during play. Small parts can be a choking hazard for children under 3.</p>'
                .'<h2>Your account</h2>'
                .'<p>Keep your login details private. You are responsible for orders placed from your account; tell us at once if you think someone else is using it.</p>'
                .'<h2>Our content</h2>'
                .'<p>Text, photos and the Toy Mart BD name and logo on this site belong to us or to the brands we sell, and may not be copied for commercial use without permission.</p>'
                .'<h2>Law</h2>'
                .'<p>These terms are governed by the laws of Bangladesh. If we change them, the version on this page at the time of your order applies to that order.</p>',
            'html_bn' => '<p>টয় মার্ট বিডিতে ব্রাউজ বা কেনাকাটা করলে এই শর্তাবলি প্রযোজ্য। অর্ডার দিলে আপনি এগুলোতে সম্মত হন।</p>'
                .'<h2>অর্ডার</h2>'
                .'<ul><li>আমরা গ্রহণ করলে অর্ডার নিশ্চিত হয়, যার মধ্যে তথ্য যাচাইয়ের জন্য একটা কল থাকতে পারে। যাচাই করতে না পারা অর্ডার আমরা ফিরিয়ে দিতে বা আটকে রাখতে পারি; না জানিয়ে কখনো বাতিল করি না।</li>'
                .'<li>প্রতিটি পণ্য সঠিকভাবে দেখাতে আমরা যথাসাধ্য চেষ্টা করি। পর্দার রং আসল খেলনা থেকে সামান্য আলাদা হতে পারে।</li>'
                .'<li>অর্ডারের পর কোনো পণ্য স্টকে না থাকলে আমরা জানাই এবং সেটার জন্য দেওয়া টাকা ফেরত দিই।</li></ul>'
                .'<h2>দাম ও পেমেন্ট</h2>'
                .'<ul><li>দাম বাংলাদেশি টাকায় (৳), প্রযোজ্য ক্ষেত্রে ভ্যাটসহ। নিশ্চিত করার আগে চেকআউটে ডেলিভারি চার্জ দেখানো হয়।</li>'
                .'<li>সাইটে কোনো দাম স্পষ্টতই ভুল হলে, অর্ডার পাঠানোর আগে আমরা যোগাযোগ করব — আপনি বাতিল করতে বা সঠিক দাম দিতে পারেন।</li>'
                .'<li>ক্যাশ অন ডেলিভারিতে বা সুযোগ থাকলে অনলাইনে পেমেন্ট করতে পারেন। কুপন ও অফার নগদ টাকায় বদলানো যায় না এবং সেগুলোর নিজস্ব শর্ত থাকতে পারে।</li></ul>'
                .'<h2>ডেলিভারি, রিটার্ন ও টাকা ফেরত</h2>'
                .'<p>ডেলিভারি আমাদের <a href="/bn/delivery-policy">ডেলিভারি নীতি</a> আর রিটার্ন আমাদের <a href="/bn/returns-policy">রিটার্ন নীতি</a> অনুযায়ী হয়, যা এই শর্তাবলির অংশ।</p>'
                .'<h2>নিরাপত্তা</h2>'
                .'<p>প্রতিটি খেলনার বয়সের লেবেল ও প্রস্তুতকারকের নির্দেশনা মেনে চলুন, আর খেলার সময় ছোট শিশুদের দেখে রাখুন। ছোট অংশ 3 বছরের কম বয়সী শিশুর গলায় আটকে যেতে পারে।</p>'
                .'<h2>আপনার অ্যাকাউন্ট</h2>'
                .'<p>লগইনের তথ্য গোপন রাখুন। আপনার অ্যাকাউন্ট থেকে দেওয়া অর্ডারের দায় আপনার; অন্য কেউ ব্যবহার করছে মনে হলে সঙ্গে সঙ্গে জানান।</p>'
                .'<h2>আমাদের কনটেন্ট</h2>'
                .'<p>এই সাইটের লেখা, ছবি এবং টয় মার্ট বিডির নাম ও লোগো আমাদের বা আমরা যে ব্র্যান্ড বিক্রি করি তাদের; অনুমতি ছাড়া বাণিজ্যিক কাজে নকল করা যাবে না।</p>'
                .'<h2>আইন</h2>'
                .'<p>এই শর্তাবলি বাংলাদেশের আইন অনুযায়ী পরিচালিত। আমরা এগুলো বদলালে, আপনার অর্ডারের সময় এই পাতায় থাকা সংস্করণটি সেই অর্ডারে প্রযোজ্য।</p>',
        ], []]];
    }

    /** @return list<array{BlockType, string, array<string, mixed>, array<string, bool>}> */
    private function faq(): array
    {
        return [
            [BlockType::Faq, 'Ordering & payment', [
                'title' => 'Ordering and payment', 'title_bn' => 'অর্ডার ও পেমেন্ট',
                'items' => [
                    $this->qa('How do I place an order?', 'Add toys to your cart, go to checkout, and enter your name, phone number and address. You do not need an account. We may call to confirm a first order before sending it.',
                        'কীভাবে অর্ডার করব?', 'খেলনা কার্টে যোগ করুন, চেকআউটে যান, তারপর নাম, ফোন নম্বর ও ঠিকানা দিন। অ্যাকাউন্ট লাগবে না। প্রথম অর্ডার পাঠানোর আগে আমরা ফোন করে নিশ্চিত করতে পারি।'),
                    $this->qa('Can I pay cash on delivery?', 'Yes, anywhere in Bangladesh. Check the parcel in front of the rider, then pay. Cash-on-delivery orders above ৳5,000 outside Dhaka need a ৳200 advance, which is deducted from what you pay the rider.',
                        'ক্যাশ অন ডেলিভারিতে টাকা দেওয়া যাবে?', 'হ্যাঁ, বাংলাদেশের যেকোনো জায়গায়। রাইডারের সামনে পার্সেল দেখে তারপর টাকা দিন। ঢাকার বাইরে ৳5,000-এর বেশি ক্যাশ অন ডেলিভারি অর্ডারে ৳200 অগ্রিম লাগে, যা রাইডারকে দেওয়া টাকা থেকে বাদ যায়।'),
                    $this->qa('Can I order by phone or WhatsApp?', 'Yes. Send us the product names or links and your address, and our team will place the order for you and confirm the total.',
                        'ফোন বা হোয়াটসঅ্যাপে অর্ডার করা যাবে?', 'হ্যাঁ। পণ্যের নাম বা লিংক আর ঠিকানা পাঠান, আমাদের টিম অর্ডার করে মোট দাম নিশ্চিত করবে।'),
                    $this->qa('How do I use a coupon?', 'Enter the code in the coupon box at checkout. The discount is shown before you confirm.',
                        'কুপন কীভাবে ব্যবহার করব?', 'চেকআউটে কুপনের ঘরে কোডটি দিন। নিশ্চিত করার আগে ছাড় দেখানো হয়।'),
                    $this->qa('Can I cancel my order?', 'Yes, free of charge until it is handed to the courier. Call or WhatsApp us with your order number.',
                        'অর্ডার বাতিল করা যাবে?', 'হ্যাঁ, কুরিয়ারে দেওয়ার আগ পর্যন্ত বিনা খরচে। অর্ডার নম্বরসহ কল বা হোয়াটসঅ্যাপ করুন।'),
                ],
            ], []],
            [BlockType::Faq, 'Delivery', [
                'title' => 'Delivery', 'title_bn' => 'ডেলিভারি',
                'items' => [
                    $this->qa('How much is delivery?', '৳70 inside Dhaka city, ৳100 in the Dhaka suburbs and ৳130 outside Dhaka, for parcels up to 1 kg; each extra kilogram adds ৳20. Delivery is free on orders of ৳3,000 or more.',
                        'ডেলিভারি চার্জ কত?', '1 কেজি পর্যন্ত পার্সেলে ঢাকা শহরে ৳70, ঢাকার আশপাশে ৳100 আর ঢাকার বাইরে ৳130; প্রতি অতিরিক্ত কেজিতে ৳20 যোগ হয়। ৳3,000 বা তার বেশি অর্ডারে ডেলিভারি ফ্রি।'),
                    $this->qa('How long does delivery take?', '1–2 working days inside Dhaka city, 2–3 in the suburbs and 3–4 outside Dhaka. Orders confirmed before 5 PM leave the same day. There are no deliveries on Fridays.',
                        'ডেলিভারিতে কত দিন লাগে?', 'ঢাকা শহরে 1–2 কর্মদিবস, আশপাশে 2–3 আর ঢাকার বাইরে 3–4 কর্মদিবস। বিকেল 5টার আগে নিশ্চিত হওয়া অর্ডার সেদিনই রওনা হয়। শুক্রবার ডেলিভারি হয় না।'),
                    $this->qa('How do I track my order?', 'Use Track order with your order number and the phone number you ordered with. We also send updates when your order is confirmed, shipped and out for delivery.',
                        'অর্ডার কীভাবে ট্র্যাক করব?', 'অর্ডার নম্বর আর যে ফোন নম্বরে অর্ডার করেছেন তা দিয়ে "অর্ডার ট্র্যাক" ব্যবহার করুন। অর্ডার নিশ্চিত, পাঠানো ও ডেলিভারিতে বের হলে আমরা আপডেটও পাঠাই।'),
                ],
            ], []],
            [BlockType::Faq, 'Returns & refunds', [
                'title' => 'Returns and refunds', 'title_bn' => 'রিটার্ন ও টাকা ফেরত',
                'items' => [
                    $this->qa('What is your return policy?', 'Tell us within 7 days of delivery. Damaged, faulty or wrong items are always replaced or refunded free. For a change of mind, the toy must be unused in its unopened box.',
                        'রিটার্ন নীতি কী?', 'ডেলিভারির 7 দিনের মধ্যে জানান। ক্ষতিগ্রস্ত, ত্রুটিপূর্ণ বা ভুল পণ্য সবসময় বিনা খরচে বদলে বা টাকা ফেরত দিই। মত বদলালে খেলনা অব্যবহৃত ও বাক্স না খোলা থাকতে হবে।'),
                    $this->qa('How do I get my money back?', 'Within 7 working days of the item reaching us, to your bKash or Nagad number or bank account — or to the original method for an online payment.',
                        'টাকা কীভাবে ফেরত পাব?', 'পণ্য আমাদের কাছে পৌঁছানোর 7 কর্মদিবসের মধ্যে, আপনার বিকাশ বা নগদ নম্বরে বা ব্যাংক অ্যাকাউন্টে — অনলাইন পেমেন্ট হলে আগের মাধ্যমে।'),
                    $this->qa('The parcel arrived damaged. What should I do?', 'If you notice it at the door, do not accept the parcel. If you find it after opening, send us a photo within 7 days. Either way we send a replacement at no cost.',
                        'পার্সেল ক্ষতিগ্রস্ত অবস্থায় এসেছে। কী করব?', 'দরজায় খেয়াল করলে পার্সেল গ্রহণ করবেন না। খোলার পর দেখলে 7 দিনের মধ্যে ছবি পাঠান। দুই ক্ষেত্রেই আমরা বিনা খরচে বদলি পাঠাই।'),
                ],
            ], []],
            [BlockType::Faq, 'Toys & safety', [
                'title' => 'Toys and safety', 'title_bn' => 'খেলনা ও নিরাপত্তা',
                'items' => [
                    $this->qa('Are your toys genuine?', 'Yes. We buy only from brands and their authorised distributors, never copies. Brand warranties apply where offered.',
                        'আপনাদের খেলনা কি আসল?', 'হ্যাঁ। আমরা শুধু ব্র্যান্ড ও তাদের অনুমোদিত পরিবেশক থেকে কিনি, কখনো নকল নয়। যেখানে আছে, ব্র্যান্ডের ওয়ারেন্টি প্রযোজ্য।'),
                    $this->qa('How do I choose a toy for my child\'s age?', 'Use Shop by age, and check the age label on each product page. For children under 3, avoid toys with small parts. Not sure? Call us — we are happy to suggest something.',
                        'সন্তানের বয়স অনুযায়ী খেলনা কীভাবে বাছব?', '"বয়স অনুযায়ী কিনুন" ব্যবহার করুন, আর প্রতিটি পণ্যের পাতায় বয়সের লেবেল দেখুন। 3 বছরের কম বয়সী শিশুর জন্য ছোট অংশের খেলনা এড়িয়ে চলুন। নিশ্চিত না হলে ফোন করুন — আমরা খুশি মনে পরামর্শ দেব।'),
                    $this->qa('Do battery toys come with batteries?', 'The product page says whether batteries are included. Where they are not, it lists the size and number you need.',
                        'ব্যাটারির খেলনার সঙ্গে ব্যাটারি থাকে?', 'ব্যাটারি দেওয়া আছে কি না পণ্যের পাতায় লেখা থাকে। না থাকলে কোন সাইজের কয়টি লাগবে তা দেওয়া থাকে।'),
                ],
            ], []],
            [BlockType::ContactInfo, 'Still have a question?', [
                'title' => 'Still have a question?', 'title_bn' => 'আরও প্রশ্ন আছে?',
                'sub' => 'Our team is happy to help — call, message or email us.', 'sub_bn' => 'আমাদের টিম খুশি মনে সাহায্য করবে — কল, মেসেজ বা ইমেইল করুন।',
                'show_address' => false,
            ], []],
        ];
    }

    /** @return array{question: string, answer: string, question_bn: string, answer_bn: string} */
    private function qa(string $question, string $answer, string $questionBn, string $answerBn): array
    {
        return ['question' => $question, 'answer' => $answer, 'question_bn' => $questionBn, 'answer_bn' => $answerBn];
    }
}
