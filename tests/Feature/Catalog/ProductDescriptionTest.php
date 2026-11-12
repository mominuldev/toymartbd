<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductDescription;
use App\Domain\Seo\Services\ProductSeo;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;

/*
 * The rich-text product description (RichTextToolbar 201:1570): stored as HTML, sanitised
 * on save by an allowlist in the Catalog domain; legacy plain text reads as paragraphs;
 * SEO reads plain words.
 */

beforeEach(function (): void {
    config(['app.url' => 'https://admin.toymart.test', 'media.cdn_url' => 'https://media.toymart.test']);
});

describe('ProductDescription::sanitize', function (): void {
    it('keeps the allowed formatting', function (): void {
        $html = '<h2>Build</h2><p><strong>500</strong> <em>bricks</em><br>in a box</p><ul><li>One</li></ul><ol><li>Two</li></ol><blockquote>Fun</blockquote><h3>Care</h3>';

        expect(ProductDescription::sanitize($html))->toBe('<h2>Build</h2><p><strong>500</strong> <em>bricks</em><br />in a box</p><ul><li>One</li></ul><ol><li>Two</li></ol><blockquote>Fun</blockquote><h3>Care</h3>');
    });

    it('drops script, style and iframe with their content', function (): void {
        $clean = ProductDescription::sanitize('<p>Safe</p><script>alert(1)</script><style>p{color:red}</style><iframe src="https://evil.example"></iframe>');

        expect($clean)->toBe('<p>Safe</p>');
    });

    it('strips event handlers and style attributes', function (): void {
        $clean = ProductDescription::sanitize('<p onclick="alert(1)" style="color:red" class="x">Hi</p><strong onmouseover="x()">B</strong>');

        expect($clean)->toBe('<p>Hi</p><strong>B</strong>')
            ->and($clean)->not->toContain('onclick')
            ->and($clean)->not->toContain('style');
    });

    it('removes javascript: and data: links but keeps http(s) and mailto', function (): void {
        $clean = (string) ProductDescription::sanitize(
            '<p><a href="javascript:alert(1)">x</a> <a href="JaVaScRiPt:alert(1)">y</a> <a href="data:text/html,hi">z</a>'
            .' <a href="https://example.com/a?b=1&c=2">ext</a> <a href="mailto:help@toymartbd.com">mail</a> <a href="https://toymartbd.com/products/x">own</a></p>'
        );

        expect($clean)->not->toContain('javascript')
            ->and($clean)->not->toContain('data:')
            ->and($clean)->toContain('<a href="https://example.com/a?b=1&amp;c=2" rel="noopener nofollow">ext</a>')
            ->and($clean)->toContain('<a href="mailto:help@toymartbd.com">mail</a>')
            ->and($clean)->toContain('<a href="https://toymartbd.com/products/x">own</a>');
    });

    it('keeps only images from our own media', function (): void {
        $clean = (string) ProductDescription::sanitize(
            '<p>a</p><img src="https://evil.example/x.png" alt="evil">'
            .'<img src="//evil.example/m/1/x.webp" alt="proto">'
            .'<img src="https://admin.toymart.test/m/12/brick-box.webp" alt="Brick box" width="1200" height="900" onerror="x()">'
            .'<img src="/m/13/train.webp" alt="ট্রেন">'
            .'<img src="https://media.toymart.test/media/ab/800.webp" alt="cdn" width="12px">'
            .'<img src="https://admin.toymart.test/storage/x.png" alt="not media">'
        );

        expect($clean)->not->toContain('evil')
            ->and($clean)->not->toContain('onerror')
            ->and($clean)->not->toContain('not media')
            ->and($clean)->toContain('<img src="https://admin.toymart.test/m/12/brick-box.webp" alt="Brick box" width="1200" height="900" />')
            ->and($clean)->toContain('<img src="/m/13/train.webp" alt="ট্রেন" />')
            ->and($clean)->toContain('<img src="https://media.toymart.test/media/ab/800.webp" alt="cdn" />');
    });

    it('unwraps unknown elements but keeps their text', function (): void {
        expect(ProductDescription::sanitize('<div><span>Kept</span> <u>text</u></div>'))->toBe('Kept text');
    });

    it('converts plain text into paragraphs split on blank lines', function (): void {
        $text = "500 colourful bricks.\nAges 4+.\n\n• 500 pieces < 10 colours & a box";

        expect(ProductDescription::sanitize($text))->toBe('<p>500 colourful bricks.<br />Ages 4+.</p><p>• 500 pieces &lt; 10 colours &amp; a box</p>');
    });

    it('preserves Bangla text', function (): void {
        $bn = "৫০০টি রঙিন ব্লক, একটি মজবুত বাক্সে।\n\nবয়স ৪+";

        expect(ProductDescription::sanitize($bn))->toBe('<p>৫০০টি রঙিন ব্লক, একটি মজবুত বাক্সে।</p><p>বয়স ৪+</p>')
            ->and(ProductDescription::sanitize('<p><strong>কাঠের</strong> ট্রেন</p>'))->toBe('<p><strong>কাঠের</strong> ট্রেন</p>');
    });

    it('returns null for empty or tag-only input', function (): void {
        expect(ProductDescription::sanitize(null))->toBeNull()
            ->and(ProductDescription::sanitize('   '))->toBeNull()
            ->and(ProductDescription::sanitize('<p></p>'))->toBeNull()
            ->and(ProductDescription::sanitize('<script>x</script>'))->toBeNull();
    });

    it('reads stored HTML as plain text for search and SEO', function (): void {
        expect(ProductDescription::plainText('<p>One&amp;two</p><ul><li>Three</li><li>Four</li></ul>'))->toBe('One&two Three Four')
            ->and(ProductDescription::plainText("Plain\n\ntext"))->toBe('Plain text');
    });
});

describe('product save', function (): void {
    beforeEach(function (): void {
        Http::preventStrayRequests();
        config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actingAs(User::factory()->withRole(Role::Manager)->create()->refresh(), 'web');
    });

    it('sanitises both languages on save and returns the HTML', function (): void {
        $category = Category::factory()->create();

        $response = $this->postJson('/api/v1/admin/products', [
            'name' => 'Brick Box 500',
            'type' => 'simple',
            'status' => 'draft',
            'primary_category_id' => $category->id,
            'category_ids' => [$category->id],
            'description' => '<p onclick="steal()">Bricks <strong>galore</strong></p><script>alert(1)</script><iframe src="https://evil.example"></iframe><img src="https://evil.example/x.png">',
            'description_bn' => "রঙিন ব্লক\n\nবয়স ৪+",
            'variants' => [['price' => '1850']],
        ]);

        $response->assertCreated()
            ->assertJsonPath('description', '<p>Bricks <strong>galore</strong></p>')
            ->assertJsonPath('description_bn', '<p>রঙিন ব্লক</p><p>বয়স ৪+</p>');

        $product = Product::query()->findOrFail($response->json('id'));
        expect($product->description)->toBe('<p>Bricks <strong>galore</strong></p>')
            ->and($product->description_bn)->toBe('<p>রঙিন ব্লক</p><p>বয়স ৪+</p>');
    });

    it('serves a legacy plain-text description as paragraphs and keeps the SEO summary plain', function (): void {
        $product = Product::factory()->create(['description' => "First paragraph.\n\nSecond paragraph.", 'short_description' => null]);

        $this->getJson("/api/v1/admin/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('description', '<p>First paragraph.</p><p>Second paragraph.</p>');

        $product->forceFill(['description' => '<p>Rich <strong>text</strong></p><ul><li>Item</li></ul>'])->save();
        expect(app(ProductSeo::class)->defaults($product->refresh())['description'])->toBe('Rich text Item');
    });
});
