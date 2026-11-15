<?php

declare(strict_types=1);

use App\Domain\Content\Actions\BuildPageLayoutFromContent;
use App\Domain\Content\Models\CmsPage;
use Illuminate\Database\Migrations\Migration;

/**
 * Every static page is now built from sections (owner decision 2026-11-16). Moves each existing
 * page's rich text and FAQ into its own layout and publishes it, so live pages look the same.
 * Data only, and idempotent: a page that already has a layout is skipped. `cms_pages.content*`
 * and `faq_items` stay (expand-and-contract); dropping them is a later migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $build = app(BuildPageLayoutFromContent::class);
        CmsPage::query()->orderBy('id')->each(static function (CmsPage $page) use ($build): void {
            $build->handle($page);
        });
    }

    public function down(): void
    {
        // Nothing to undo safely: published layout versions are append-only.
    }
};
