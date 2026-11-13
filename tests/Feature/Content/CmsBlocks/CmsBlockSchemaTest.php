<?php

declare(strict_types=1);

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Content\Blocks\Enums\BlockType;
use App\Domain\Content\Blocks\Models\CmsBlock;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Content\CmsBlocks\CmsKit;

/*
 * CMS block JSON schemas (Phase 7): every type validates server-side through the block
 * FormRequest; defaults are valid; invalid content is a 422 naming the field.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    CmsKit::actAsEditor();
});

/** @return list<string> */
function cmsErrorFields(Illuminate\Testing\TestResponse $response): array
{
    return collect($response->json('errors'))->pluck('field')->unique()->values()->all();
}

it('accepts every type with its own defaults, and with content omitted', function (BlockType $type): void {
    $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => $type->value])->assertCreated()->assertJsonPath('type', $type->value);
    $defaults = $type->schema()->defaults();
    $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => $type->value, 'content' => $defaults])->assertCreated();

    // Stored content always has the normalised shape.
    expect(CmsBlock::query()->latest('id')->first()?->content)->toBe($type->schema()->normalize($defaults));
})->with(fn () => array_map(static fn (BlockType $t): array => [$t], BlockType::cases()));

it('rejects an unknown type', function (): void {
    $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'carousel_of_doom'])->assertUnprocessable();
});

it('rejects invalid content per type', function (string $type, array $content, string $field): void {
    $response = $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => $type, 'content' => $content])->assertUnprocessable();

    expect(cmsErrorFields($response))->toContain('content.'.$field);
})->with([
    'hero without slides' => ['hero', ['slides' => []], 'slides'],
    'hero with six slides' => ['hero', ['slides' => array_fill(0, 6, ['headline' => 'x'])], 'slides'],
    'hero slide without headline' => ['hero', ['slides' => [['sub' => 'x']]], 'slides.0.headline'],
    'hero javascript link' => ['hero', ['slides' => [['headline' => 'x', 'cta_label' => 'Go', 'cta_href' => 'javascript:alert(1)']]], 'slides.0.cta_href'],
    'hero protocol-relative link' => ['hero', ['slides' => [['headline' => 'x', 'cta_label' => 'Go', 'cta_href' => '//evil.example']]], 'slides.0.cta_href'],
    'hero label without link' => ['hero', ['slides' => [['headline' => 'x', 'cta_label' => 'Go']]], 'slides.0.cta_href'],
    'hero unknown media' => ['hero', ['slides' => [['headline' => 'x', 'media_id' => 999999]]], 'slides.0.media_id'],
    'category tiles bad source' => ['category_tiles', ['title' => 'x', 'source' => 'random'], 'source'],
    'category tiles manual without ids' => ['category_tiles', ['title' => 'x', 'source' => 'manual'], 'category_ids'],
    'category tiles unknown id' => ['category_tiles', ['title' => 'x', 'source' => 'manual', 'category_ids' => [999999]], 'category_ids.0'],
    'age tiles without title' => ['age_tiles', ['title' => ''], 'title'],
    'rail bad rule' => ['product_rail', ['title' => 'x', 'rule' => 'random'], 'rule'],
    'rail category rule without category' => ['product_rail', ['title' => 'x', 'rule' => 'category'], 'category_id'],
    'rail brand rule without brand' => ['product_rail', ['title' => 'x', 'rule' => 'brand'], 'brand_id'],
    'rail manual without products' => ['product_rail', ['title' => 'x', 'rule' => 'manual'], 'product_ids'],
    'rail limit too big' => ['product_rail', ['title' => 'x', 'rule' => 'new', 'limit' => 40], 'limit'],
    'rail odd window' => ['product_rail', ['title' => 'x', 'rule' => 'best_sellers', 'window_days' => 3], 'window_days'],
    'rail min above limit' => ['product_rail', ['title' => 'x', 'rule' => 'new', 'limit' => 4, 'min_items' => 6], 'min_items'],
    'brand strip unknown brand' => ['brand_strip', ['title' => 'x', 'brand_ids' => [999999]], 'brand_ids.0'],
    'usp one item' => ['usp_band', ['title' => 'x', 'items' => [['icon' => 'cod', 'title' => 'COD']]], 'items'],
    'usp unknown icon' => ['usp_band', ['title' => 'x', 'items' => [['icon' => 'rocket', 'title' => 'a'], ['icon' => 'cod', 'title' => 'b']]], 'items.0.icon'],
    'promo bad side' => ['promo_split', ['headline' => 'x', 'image_side' => 'top'], 'image_side'],
    'testimonials limit' => ['testimonials', ['title' => 'x', 'limit' => 50], 'limit'],
    'blog teaser limit' => ['blog_teaser', ['title' => 'x', 'limit' => 9], 'limit'],
    'rich text empty' => ['rich_text', ['html' => ''], 'html'],
    'faq no items' => ['faq', ['title' => 'x', 'items' => []], 'items'],
    'faq item without answer' => ['faq', ['title' => 'x', 'items' => [['question' => 'Q?']]], 'items.0.answer'],
]);

it('accepts a document media id only when it is an image', function (): void {
    $document = Media::factory()->document()->create();
    $image = Media::factory()->create();

    $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'promo_split', 'content' => ['headline' => 'x', 'media_id' => $document->id]])->assertUnprocessable();
    $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'promo_split', 'content' => ['headline' => 'x', 'media_id' => $image->id]])
        ->assertCreated()->assertJsonPath('content.media_id', $image->id);
});

it('sanitises rich text on save in both languages', function (): void {
    $response = $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'rich_text', 'content' => [
        'html' => '<p onclick="x()">Hello <script>alert(1)</script><a href="javascript:alert(1)">bad</a></p>',
        'html_bn' => '<p>হ্যালো</p><iframe src="https://evil.example"></iframe>',
    ]])->assertCreated();

    expect($response->json('content.html'))->toBe('<p>Hello <a>bad</a></p>')
        ->and($response->json('content.html_bn'))->toBe('<p>হ্যালো</p>');
});

it('drops unknown keys and keeps manual picks in the editor\'s order', function (): void {
    $a = Category::factory()->create();
    $b = Category::factory()->create();
    $brand = Brand::factory()->create();
    $product = App\Domain\Catalog\Models\Product::factory()->create();

    $response = $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'category_tiles', 'content' => [
        'title' => ' Shop ', 'title_bn' => '', 'source' => 'manual', 'category_ids' => [$b->id, $a->id], 'evil' => '<script>',
    ]])->assertCreated();

    expect($response->json('content'))->toBe([
        'title' => 'Shop', 'title_bn' => null, 'source' => 'manual', 'category_ids' => [$b->id, $a->id], 'limit' => 8, 'show_see_all' => true,
    ]);

    $rail = $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'product_rail', 'content' => [
        'title' => 'LEGO', 'rule' => 'brand', 'brand_id' => $brand->id, 'product_ids' => [$product->id],
    ]])->assertCreated();
    expect($rail->json('content.product_ids'))->toBe([])->and($rail->json('content.brand_id'))->toBe($brand->id);
});

it('validates a PATCH against the block\'s own type', function (): void {
    $id = $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'faq'])->json('id');

    $this->patchJson(CmsKit::ADMIN.'/blocks/'.$id, ['content' => ['html' => '<p>x</p>']])->assertUnprocessable();
    $this->patchJson(CmsKit::ADMIN.'/blocks/'.$id, ['content' => ['title' => 'FAQ', 'items' => [['question' => 'Q?', 'answer' => 'A.']]]])
        ->assertOk()->assertJsonPath('content.items.0.answer', 'A.');
});
