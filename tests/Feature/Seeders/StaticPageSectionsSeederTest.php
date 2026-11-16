<?php

declare(strict_types=1);

use App\Domain\Content\Actions\BuildPageLayoutFromContent;
use App\Domain\Content\Blocks\Enums\BlockType;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Domain\Content\Blocks\ValueObjects\LayoutKey;
use App\Domain\Content\Enums\PageStatus;
use App\Domain\Content\Models\CmsPage;
use Database\Seeders\StaticPageSectionsSeeder;
use Database\Seeders\StaticPagesSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Default section content for the static pages (rule 34a): every launch page gets a published
 * layout built from the block library, in both languages; the owner's own layouts are never
 * touched; the placeholder layout the migration made is replaced.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->seed(StaticPagesSeeder::class);
});

/** @return list<string> */
function seededTypes(string $slug): array
{
    $page = CmsPage::query()->where('slug', $slug)->firstOrFail();

    return array_column(CmsLayoutVersion::latestFor(LayoutKey::page($page->id))->blocks ?? [], 'type');
}

it('builds and publishes every static page from sections', function (): void {
    $this->seed(StaticPageSectionsSeeder::class);

    expect(seededTypes('about'))->toBe(['story_hero', 'feature_tiles', 'numbered_cards', 'cta_band'])
        ->and(seededTypes('contact'))->toBe(['contact_info', 'rich_text', 'faq'])
        ->and(seededTypes('delivery-policy'))->toBe(['rich_text', 'faq'])
        ->and(seededTypes('returns-policy'))->toBe(['rich_text', 'faq'])
        ->and(seededTypes('privacy-policy'))->toBe(['rich_text'])
        ->and(seededTypes('terms'))->toBe(['rich_text'])
        ->and(seededTypes('faq'))->toBe(['faq', 'faq', 'faq', 'faq', 'contact_info']);

    // Every section validates against its schema and carries Bangla copy; nothing says PLACEHOLDER.
    foreach (CmsBlock::query()->get() as $block) {
        $content = $block->content;
        expect(validator($content, $block->type->schema()->rules())->passes())->toBeTrue("{$block->type->value} on {$block->placement->value}")
            ->and(json_encode($content, JSON_UNESCAPED_UNICODE))->not->toContain('PLACEHOLDER');
        if ($block->type === BlockType::RichText) {
            expect($content['html_bn'])->not->toBeNull();
        }
    }

    // Outside `local` the pages stay drafts: the policy text is the owner's to approve.
    expect(CmsPage::query()->where('status', PageStatus::Published)->count())->toBe(0);
});

it('replaces the migrated placeholder layout but never an edited one', function (): void {
    $about = CmsPage::query()->where('slug', 'about')->firstOrFail();
    $terms = CmsPage::query()->where('slug', 'terms')->firstOrFail();
    app(BuildPageLayoutFromContent::class)->handle($about);
    app(BuildPageLayoutFromContent::class)->handle($terms);
    CmsBlock::query()->where('placement', LayoutKey::page($terms->id)->value)->update(['content' => ['html' => '<p>Our own terms.</p>', 'html_bn' => null]]);

    $this->seed(StaticPageSectionsSeeder::class);
    $this->seed(StaticPageSectionsSeeder::class); // idempotent

    expect(seededTypes('about'))->toBe(['story_hero', 'feature_tiles', 'numbered_cards', 'cta_band'])
        ->and(CmsLayoutVersion::query()->where('placement', LayoutKey::page($about->id)->value)->count())->toBe(2)
        ->and(CmsBlock::query()->where('placement', LayoutKey::page($terms->id)->value)->value('content')['html'])->toBe('<p>Our own terms.</p>');
});
