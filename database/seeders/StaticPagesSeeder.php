<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Content\Enums\PageStatus;
use App\Domain\Content\Enums\PageTemplate;
use App\Domain\Content\Models\CmsPage;
use Illuminate\Database\Seeder;

/**
 * The launch static pages (DATABASE-DESIGN §9 "default CMS blocks and static pages"), seeded as
 * DRAFTS with placeholder copy: the policies are the owner's legal text, not ours to write
 * (UI-UX-DESIGN-SPEC §10). Idempotent — an existing slug is never touched, so a re-run never
 * overwrites what the owner wrote.
 */
final class StaticPagesSeeder extends Seeder
{
    /** slug => [title, title_bn, template] */
    private const array PAGES = [
        'about' => ['About Toy Mart BD', 'টয় মার্ট বিডি সম্পর্কে', PageTemplate::Default],
        'contact' => ['Contact us', 'যোগাযোগ', PageTemplate::Sidebar],
        'delivery-policy' => ['Delivery policy', 'ডেলিভারি নীতি', PageTemplate::Sidebar],
        'returns-policy' => ['Returns & refunds', 'রিটার্ন ও রিফান্ড', PageTemplate::Sidebar],
        'privacy-policy' => ['Privacy policy', 'গোপনীয়তা নীতি', PageTemplate::Sidebar],
        'terms' => ['Terms & conditions', 'শর্তাবলি', PageTemplate::Sidebar],
        'faq' => ['Frequently asked questions', 'সাধারণ প্রশ্নোত্তর', PageTemplate::Faq],
    ];

    public function run(): void
    {
        foreach (self::PAGES as $slug => [$title, $titleBn, $template]) {
            CmsPage::query()->firstOrCreate(['slug' => $slug], [
                'title' => $title,
                'title_bn' => $titleBn,
                'template' => $template,
                'status' => PageStatus::Draft,
                'content' => '<p>PLACEHOLDER — the owner writes this page before it is published.</p>',
                'content_bn' => '<p>PLACEHOLDER — প্রকাশের আগে মালিক এই পৃষ্ঠাটি লিখবেন।</p>',
            ]);
        }
    }
}
