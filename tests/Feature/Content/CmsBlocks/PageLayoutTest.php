<?php

declare(strict_types=1);

use App\Domain\Content\Actions\BuildPageLayoutFromContent;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Domain\Content\Blocks\ValueObjects\LayoutKey;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Content\Models\CmsPage;
use App\Domain\Content\Services\PageText;
use App\Support\PreviewToken;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Content\CmsBlocks\CmsKit;

/*
 * Static pages built from sections, like the homepage (owner decision 2026-11-16): each page has
 * its own draft, versions and preview; the storefront reads its live sections per locale; legacy
 * page content moves into sections; layouts never leak into each other.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->editor = CmsKit::actAsEditor();
});

function plPage(array $attributes = []): CmsPage
{
    return CmsPage::query()->create([
        'slug' => 'delivery-policy', 'title' => 'Delivery policy', 'title_bn' => 'ডেলিভারি নীতি',
        'status' => 'published', 'published_at' => now()->subDay(), ...$attributes,
    ]);
}

function plAdmin(CmsPage $page): string
{
    return '/api/v1/admin/content/pages/'.$page->id.'/layout';
}

it('builds a page from sections with its own draft, publish and versions', function (): void {
    $page = plPage();
    $other = plPage(['slug' => 'terms', 'title' => 'Terms']);

    $text = $this->postJson(plAdmin($page).'/blocks', ['type' => 'rich_text', 'content' => ['html' => '<p>Dhaka in 24 hours.</p>']])->assertCreated()->json('id');
    $this->postJson(plAdmin($page).'/blocks', ['type' => 'faq'])->assertCreated();
    $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'hero'])->assertCreated();

    $draft = $this->getJson(plAdmin($page))->assertOk()
        ->assertJsonPath('placement', 'page:'.$page->id)
        ->assertJsonPath('live', null)
        ->assertJsonPath('has_unpublished_changes', true);
    expect(array_column($draft->json('blocks'), 'type'))->toBe(['rich_text', 'faq'])
        // The homepage and the other page keep their own drafts.
        ->and(array_column($this->getJson(CmsKit::ADMIN)->json('blocks'), 'type'))->toBe(['hero'])
        ->and($this->getJson(plAdmin($other))->json('blocks'))->toBe([]);

    $this->postJson(plAdmin($page).'/publish', ['note' => 'First'])->assertCreated()->assertJsonPath('version', 1);
    $this->postJson(plAdmin($page).'/publish')->assertConflict();
    $this->postJson(plAdmin($other).'/blocks', ['type' => 'rich_text'])->assertCreated();
    // Version numbers are per layout.
    $this->postJson(plAdmin($other).'/publish')->assertCreated()->assertJsonPath('version', 1);

    $this->patchJson(plAdmin($page).'/blocks/'.$text, ['content' => ['html' => '<p>Changed</p>']])->assertOk();
    $this->postJson(plAdmin($page).'/publish')->assertCreated()->assertJsonPath('version', 2);
    $first = CmsLayoutVersion::query()->ofPlacement(LayoutKey::page($page->id))->where('version', 1)->firstOrFail();

    expect(array_column($this->getJson(plAdmin($page).'/versions')->assertOk()->json('data'), 'version'))->toBe([2, 1]);
    $this->postJson(plAdmin($page).'/versions/'.$first->id.'/revert')->assertCreated()->assertJsonPath('version', 3)->assertJsonPath('restored_from_version', 1);
});

it('refuses a block or version that belongs to another layout', function (): void {
    $page = plPage();
    $homeBlock = CmsKit::block('faq');
    $pageBlock = CmsKit::block('faq', [], ['placement' => LayoutKey::page($page->id)]);

    $this->patchJson(plAdmin($page).'/blocks/'.$homeBlock->id, ['label' => 'x'])->assertNotFound();
    $this->deleteJson(plAdmin($page).'/blocks/'.$homeBlock->id)->assertNotFound();
    $this->postJson(plAdmin($page).'/blocks/'.$homeBlock->id.'/duplicate')->assertNotFound();
    $this->patchJson(CmsKit::ADMIN.'/blocks/'.$pageBlock->id, ['label' => 'x'])->assertNotFound();
    expect(CmsBlock::query()->find($homeBlock->id))->not->toBeNull();

    $this->postJson(CmsKit::ADMIN.'/publish')->assertCreated();
    $homeVersion = CmsLayoutVersion::latestFor(LayoutKey::home());
    $this->postJson(plAdmin($page).'/versions/'.$homeVersion?->id.'/revert')->assertNotFound();
});

it('revalidates the page tags when its layout is published', function (): void {
    Bus::fake([RevalidateStorefrontTags::class]);
    $page = plPage();
    $this->postJson(plAdmin($page).'/blocks', ['type' => 'rich_text'])->assertCreated();
    $this->postJson(plAdmin($page).'/publish')->assertCreated();

    Bus::assertDispatched(RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['content:pages', 'content:page:delivery-policy']);
});

it('serves a live page with its live sections per locale, and 404s one that is not live', function (): void {
    $page = plPage();
    $this->postJson(plAdmin($page).'/blocks', ['type' => 'rich_text', 'content' => ['html' => '<p>English body</p>', 'html_bn' => '<p>বাংলা লেখা</p>']])->assertCreated();
    $this->postJson(plAdmin($page).'/blocks', ['type' => 'faq', 'show_bn' => false])->assertCreated();
    $token = CmsKit::storefrontToken();

    // Never published from the builder: the page with no sections.
    $this->withToken($token)->getJson('/api/v1/storefront/content/pages/delivery-policy?locale=en')->assertOk()
        ->assertJsonPath('version', null)->assertJsonPath('blocks', [])->assertJsonPath('page.slug', 'delivery-policy');

    $this->postJson(plAdmin($page).'/publish')->assertCreated();
    $en = $this->withToken($token)->getJson('/api/v1/storefront/content/pages/delivery-policy?locale=en')->assertOk()->assertJsonPath('version', 1);
    expect(array_column($en->json('blocks'), 'type'))->toBe(['rich_text', 'faq'])
        ->and($en->json('blocks.0.content.html'))->toBe('<p>English body</p>')
        ->and($en->json('page.seo.description'))->toBe('English body');
    $bn = $this->withToken($token)->getJson('/api/v1/storefront/content/pages/delivery-policy?locale=bn')->assertOk();
    expect(array_column($bn->json('blocks'), 'type'))->toBe(['rich_text'])
        ->and($bn->json('blocks.0.content.html'))->toBe('<p>বাংলা লেখা</p>');

    $this->withToken($token)->getJson('/api/v1/storefront/content/pages/delivery-policy')->assertStatus(400);
    $this->withToken($token)->getJson('/api/v1/storefront/content/pages/delivery-policy?locale=en&x=1')->assertStatus(400);
    $page->forceFill(['status' => 'draft'])->save();
    $this->withToken($token)->getJson('/api/v1/storefront/content/pages/delivery-policy?locale=en')->assertNotFound();
});

it('previews one page draft with a page token, even before the page is live', function (): void {
    $page = plPage(['status' => 'draft']);
    $other = plPage(['slug' => 'terms', 'title' => 'Terms']);
    $this->postJson(plAdmin($page).'/blocks', ['type' => 'rich_text', 'content' => ['html' => '<p>Draft body</p>']])->assertCreated();
    $minted = $this->postJson(plAdmin($page).'/preview-token', ['locale' => 'en'])->assertOk()->json('token');
    $storefront = CmsKit::storefrontToken();

    $preview = $this->withToken($storefront)->getJson('/api/v1/storefront/content/pages/preview?locale=en', ['X-Preview-Token' => $minted])->assertOk()
        ->assertJsonPath('preview', true)->assertJsonPath('page.slug', 'delivery-policy');
    expect($preview->json('blocks.0.content.html'))->toBe('<p>Draft body</p>');

    // A homepage token, a token for another page's id, or none: refused / that page only.
    $home = PreviewToken::issue('home_draft', $page->id);
    $this->withToken($storefront)->getJson('/api/v1/storefront/content/pages/preview?locale=en', ['X-Preview-Token' => $home])->assertForbidden();
    $this->withToken($storefront)->getJson('/api/v1/storefront/content/pages/preview?locale=en')->assertForbidden();
    $this->withToken($storefront)->getJson('/api/v1/storefront/content/pages/preview?locale=en', ['X-Preview-Token' => PreviewToken::issue('page_draft', $other->id)])
        ->assertOk()->assertJsonPath('page.slug', 'terms')->assertJsonPath('blocks', []);
});

it('moves a legacy page into sections once and publishes it unchanged', function (): void {
    $faq = array_map(static fn (int $i): array => ['question' => "Q{$i}?", 'question_bn' => null, 'answer' => "<p>Answer <strong>{$i}</strong></p>", 'answer_bn' => null], range(1, 25));
    $page = plPage(['content' => '<p>Old body</p>', 'content_bn' => '<p>পুরনো লেখা</p>', 'faq_items' => $faq]);
    $empty = plPage(['slug' => 'blank', 'title' => 'Blank']);
    $build = app(BuildPageLayoutFromContent::class);

    expect($build->handle($page))->toBe(3)
        ->and($build->handle($page))->toBe(0)
        ->and($build->handle($empty))->toBe(0)
        ->and(CmsLayoutVersion::latestFor(LayoutKey::page($empty->id)))->toBeNull();

    $live = CmsLayoutVersion::latestFor(LayoutKey::page($page->id));
    expect($live?->version)->toBe(1)
        ->and(array_column($live->blocks ?? [], 'type'))->toBe(['rich_text', 'faq', 'faq'])
        ->and($live->blocks[0]['content']['html_bn'] ?? null)->toBe('<p>পুরনো লেখা</p>')
        ->and(count($live->blocks[1]['content']['items']))->toBe(20)
        ->and(count($live->blocks[2]['content']['items']))->toBe(5)
        ->and($live->blocks[1]['content']['items'][0]['answer'])->toBe('Answer 1')
        ->and(app(PageText::class)->plain($page))->toBe('Old body');
});
