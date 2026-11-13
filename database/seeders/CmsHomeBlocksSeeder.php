<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Content\Blocks\Actions\CreateBlock;
use App\Domain\Content\Blocks\Actions\PublishLayout;
use App\Domain\Content\Blocks\DTOs\BlockSettings;
use App\Domain\Content\Blocks\Enums\BlockPlacement;
use App\Domain\Content\Blocks\Enums\BlockType;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use Illuminate\Database\Seeder;

/**
 * The default homepage (Phase 7): the same sections, in the same Figma order, as the storefront's
 * built-in home (P4-STO-02) — hero with the age chooser, category tiles, shop by age, best
 * sellers, new arrivals, the promise band — plus the testimonials and blog slots, which hide
 * themselves until there is content. Published as version 1. Runs once: an existing draft or
 * version is never touched, so re-seeding cannot overwrite the owner's homepage.
 */
final class CmsHomeBlocksSeeder extends Seeder
{
    public function run(CreateBlock $create, PublishLayout $publish): void
    {
        $placement = BlockPlacement::Home;

        if (CmsBlock::query()->where('placement', $placement->value)->exists() || CmsLayoutVersion::latestFor($placement) !== null) {
            return;
        }

        $rail = BlockType::ProductRail->schema();
        $blocks = [
            [BlockType::Hero, null, 'Hero'],
            [BlockType::CategoryTiles, null, 'Category tiles'],
            [BlockType::AgeTiles, null, 'Shop by age'],
            [BlockType::ProductRail, null, 'Best sellers'],
            [BlockType::ProductRail, $rail->normalize([...$rail->defaults(), 'title' => 'New arrivals', 'title_bn' => 'নতুন এসেছে', 'rule' => 'new']), 'New arrivals'],
            [BlockType::UspBand, null, 'Promise band'],
            [BlockType::Testimonials, null, 'Testimonials'],
            [BlockType::BlogTeaser, null, 'Blog teaser'],
        ];

        foreach ($blocks as [$type, $content, $label]) {
            $create->handle($placement, $type, new BlockSettings(content: $content, label: $label, present: ['label']), null, null);
        }

        $publish->handle($placement, null, 'Default homepage');
    }
}
