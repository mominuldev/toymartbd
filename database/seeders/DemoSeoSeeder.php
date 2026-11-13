<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Content\Models\CmsPage;
use App\Domain\Notification\Actions\SyncDefaultTemplates;
use App\Domain\Seo\Actions\RecordSlugChange;
use App\Domain\Seo\Actions\SaveRedirect;
use App\Domain\Seo\Actions\SaveRobotsTxt;
use App\Domain\Seo\Actions\SaveSeoMeta;
use App\Domain\Seo\DTOs\RedirectData;
use App\Domain\Seo\DTOs\SeoMetaData;
use App\Domain\Seo\Enums\RobotsDirective;
use App\Domain\Seo\Enums\SeoableType;
use App\Domain\Seo\Events\SeoPagesStale;
use App\Domain\Seo\Models\Redirect;
use App\Domain\Seo\Models\SlugHistory;
use App\Domain\Seo\Services\RobotsTxt;
use App\Domain\Seo\Services\SeoSubjects;
use App\Models\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * Demo SEO (local only, from DemoDataSeeder, last): what the SEO screens list and what the
 * storefront's redirect middleware serves.
 *
 * - seo_meta (SaveSeoMeta) for 3 products, 2 categories, a brand and the About page —
 *   English and Bangla titles/descriptions and a focus keyword; the Terms page is
 *   `noindex, follow` and kept out of the sitemap.
 * - Slug history (RecordSlugChange) for a product and a category renamed after launch: each
 *   old URL 301s to the current one. The entities keep their current slugs (other demo data
 *   and tests read them); only the history is recorded, exactly as a rename would.
 * - Manual redirects (SaveRedirect): old-site URLs (`/product/…`, `/shop/…`, `/collections/…`)
 *   to their new homes, a 302 campaign link to the Eid gift post, one pointing at a renamed
 *   product's old URL (SaveRedirect stores the final destination, never a chain) and one
 *   inactive.
 * - robots.txt (SaveRobotsTxt): the shipped default plus the order-tracking page — only
 *   when the admin never saved one.
 * - Notification templates: SyncDefaultTemplates (migrations already run it; re-running is
 *   a no-op that inserts any catalogue template still missing).
 *
 * Idempotent: seo_meta is an upsert, slug history and redirects are guarded on the old path.
 * SeoPagesStale (storefront revalidation only) is silenced.
 */
class DemoSeoSeeder extends Seeder
{
    /**
     * [type, slug, title, title_bn, description, description_bn, focus keyword, robots].
     *
     * @var list<array{SeoableType, string, string, string, string, string, string|null, RobotsDirective}>
     */
    public const array META = [
        [SeoableType::Product, 'lego-classic-creative-bricks-484', 'LEGO Classic 484 pcs price in Bangladesh | Toy Mart BD', 'লেগো ক্লাসিক 484 পিস — বাংলাদেশে দাম | টয় মার্ট বিডি', 'Buy the original LEGO Classic Creative Bricks (484 pcs) with cash on delivery anywhere in Bangladesh.', 'আসল লেগো ক্লাসিক ক্রিয়েটিভ ব্রিকস (484 পিস) কিনুন, সারা বাংলাদেশে ক্যাশ অন ডেলিভারি।', 'lego price in bangladesh', RobotsDirective::IndexFollow],
        [SeoableType::Product, 'rubiks-cube-3x3-original', "Original Rubik's Cube 3x3 in Bangladesh | Toy Mart BD", 'আসল রুবিক্স কিউব 3x3 | টয় মার্ট বিডি', "The original Rubik's 3x3 speed cube — smooth turning, genuine stickers. Delivered across Bangladesh.", 'আসল রুবিক্স 3x3 কিউব — মসৃণ ঘোরানো, আসল স্টিকার। সারা দেশে ডেলিভারি।', 'rubiks cube bd', RobotsDirective::IndexFollow],
        [SeoableType::Product, 'fisher-price-smart-stages-puppy', 'Fisher-Price Smart Stages Puppy | Toy Mart BD', 'ফিশার-প্রাইস স্মার্ট স্টেজেস পাপি | টয় মার্ট বিডি', 'A singing puppy that teaches first words and counting, for 6–36 months.', '6–36 মাসের শিশুর জন্য প্রথম শব্দ আর গোনা শেখানো গান গাওয়া পাপি।', 'fisher price toys bangladesh', RobotsDirective::IndexFollow],
        [SeoableType::Category, 'building-blocks', 'Building blocks & LEGO sets in Bangladesh | Toy Mart BD', 'বিল্ডিং ব্লক ও লেগো সেট | টয় মার্ট বিডি', 'LEGO, DUPLO, wooden and magnetic blocks for every age, with cash on delivery.', 'সব বয়সের জন্য লেগো, ডুপ্লো, কাঠের ও ম্যাগনেটিক ব্লক, ক্যাশ অন ডেলিভারি।', 'building blocks bangladesh', RobotsDirective::IndexFollow],
        [SeoableType::Category, 'baby-toddler', 'Baby & toddler toys online in Bangladesh | Toy Mart BD', 'শিশু ও বেবি খেলনা অনলাইনে | টয় মার্ট বিডি', 'Safe, age-labelled toys for babies and toddlers: activity gyms, musical toys and more.', 'শিশু ও ছোটদের জন্য নিরাপদ খেলনা: অ্যাক্টিভিটি জিম, মিউজিক্যাল খেলনা ও আরও অনেক কিছু।', 'baby toys bd', RobotsDirective::IndexFollow],
        [SeoableType::Brand, 'lego', 'LEGO in Bangladesh — original sets | Toy Mart BD', 'বাংলাদেশে আসল লেগো সেট | টয় মার্ট বিডি', 'Original LEGO sets with cash on delivery across Bangladesh.', 'সারা বাংলাদেশে ক্যাশ অন ডেলিভারিতে আসল লেগো সেট।', 'lego bangladesh', RobotsDirective::IndexFollow],
        [SeoableType::Page, 'about', 'About Toy Mart BD', 'টয় মার্ট বিডি সম্পর্কে', 'Toy Mart BD sells original toys and educational products across Bangladesh.', 'টয় মার্ট বিডি সারা বাংলাদেশে আসল খেলনা ও শিক্ষামূলক পণ্য বিক্রি করে।', null, RobotsDirective::IndexFollow],
        [SeoableType::Page, 'terms', 'Terms & conditions | Toy Mart BD', 'শর্তাবলি | টয় মার্ট বিডি', 'The terms of using Toy Mart BD.', 'টয় মার্ট বিডি ব্যবহারের শর্তাবলি।', null, RobotsDirective::NoindexFollow],
    ];

    /** @var list<array{SeoableType, string, string, int}> [type, current slug, old slug, days ago] */
    public const array SLUG_CHANGES = [
        [SeoableType::Product, 'lego-classic-creative-bricks-484', 'lego-classic-484-pcs', 30],
        [SeoableType::Category, 'puzzles-games', 'puzzles-and-board-games', 45],
    ];

    /** @var list<array{string, string, int, bool, string}> [from, to, status, active, note] */
    public const array REDIRECTS = [
        ['/product/lego-classic-484', '/products/lego-classic-creative-bricks-484', 301, true, 'Old site product URL'],
        ['/product/rubiks-cube', '/products/rubiks-cube-3x3-original', 301, true, 'Old site product URL'],
        ['/shop/baby-toys', '/categories/baby-toddler', 301, true, 'Old site category URL'],
        ['/collections/hot-wheels', '/brands/hot-wheels', 301, true, 'Old site brand collection'],
        ['/product/lego-484-classic-set', '/products/lego-classic-484-pcs', 301, true, 'Points at a renamed slug; stored as its final destination'],
        ['/eid-offer', '/blog/eid-gift-ideas-under-2000', 302, true, 'Facebook campaign link'],
        ['/winter-sale', '/blog/winter-holiday-gift-guide', 302, false, 'Goes live with the winter campaign'],
    ];

    public function run(): void
    {
        Event::fakeFor(function (): void {
            try {
                $this->meta();
                $this->slugHistory();
                $this->redirects();
                $this->robots();
            } finally {
                Carbon::setTestNow(null);
                CarbonImmutable::setTestNow(null);
            }
        }, [SeoPagesStale::class]);

        app(SyncDefaultTemplates::class)->handle();
    }

    private function meta(): void
    {
        $save = app(SaveSeoMeta::class);
        foreach (self::META as [$type, $slug, $title, $titleBn, $description, $descriptionBn, $keyword, $robots]) {
            $subject = app(SeoSubjects::class)->for($type->value)?->find(self::id($type, $slug))
                ?? throw new RuntimeException("No SEO subject for {$type->value} {$slug}.");
            $save->handle($subject, new SeoMetaData(
                title: $title,
                titleBn: $titleBn,
                description: $description,
                descriptionBn: $descriptionBn,
                robots: $robots,
                sitemapExclude: $robots !== RobotsDirective::IndexFollow,
                ogTitle: $title,
                ogDescription: $description,
                focusKeyword: $keyword,
                ogTitleBn: $titleBn,
                ogDescriptionBn: $descriptionBn,
            ));
        }
    }

    private function slugHistory(): void
    {
        $record = app(RecordSlugChange::class);
        foreach (self::SLUG_CHANGES as [$type, $slug, $old, $daysAgo]) {
            if (SlugHistory::query()->where('sluggable_type', $type->value)->where('old_slug', $old)->exists()) {
                continue;
            }
            self::at(CarbonImmutable::now()->subDays($daysAgo), static fn () => $record->handle($type->value, self::id($type, $slug), $old, $slug, true, self::actorId()));
        }
    }

    private function redirects(): void
    {
        $save = app(SaveRedirect::class);
        foreach (self::REDIRECTS as [$from, $to, $status, $active, $note]) {
            if (! Redirect::query()->where('from_path', $from)->exists()) {
                $save->handle(null, new RedirectData($from, $to, $status, $active, $note), self::actorId());
            }
        }
    }

    private function robots(): void
    {
        if (RobotsTxt::stored() !== null) {
            return;
        }
        $content = str_replace('Disallow: /api/', "Disallow: /api/\nDisallow: /track\nDisallow: /bn/track", RobotsTxt::defaultContent());
        app(SaveRobotsTxt::class)->handle("# Toy Mart BD — edited in Admin › SEO › robots.txt\n".$content, self::actorId());
    }

    private static function id(SeoableType $type, string $slug): int
    {
        $id = match ($type) {
            SeoableType::Product => Product::query()->where('slug', $slug)->value('id'),
            SeoableType::Category => Category::query()->where('slug', $slug)->value('id'),
            SeoableType::Brand => Brand::query()->where('slug', $slug)->value('id'),
            SeoableType::Page => CmsPage::query()->where('slug', $slug)->value('id'),
            default => null,
        };

        return (int) ($id ?? throw new RuntimeException("{$type->value} {$slug} missing: run the catalogue and page seeders first."));
    }

    private static function actorId(): ?int
    {
        return (DemoStaffSeeder::user(Role::ContentEditor) ?? User::query()->where('email', 'owner@toymart.test')->first())?->id;
    }

    private static function at(CarbonImmutable $instant, callable $fn): void
    {
        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);
        try {
            $fn();
        } finally {
            Carbon::setTestNow(null);
            CarbonImmutable::setTestNow(null);
        }
    }
}
