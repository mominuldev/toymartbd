<?php

declare(strict_types=1);

use App\Domain\Ai\Exceptions\AiException;
use App\Domain\Ai\Models\AiRequest;
use App\Domain\Ai\Services\AiDrivers;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Tests\Feature\Orders\Support;
use Tests\Support\RoleMatrix;

beforeEach(function (): void {
    $this->fake = AiDrivers::fake();
    Setting::put('ai.key.anthropic', 'sk-ant-test-0000000000000000', SettingGroup::Ai, true);
});

function aiCopyProduct(): Product
{
    $brand = Brand::factory()->create(['name' => 'Wonder Wood', 'name_bn' => 'ওয়ান্ডার উড']);
    $product = Product::factory()->priced('1250.00')->create([
        'name' => 'Rainbow Stacker',
        'name_bn' => 'রংধনু স্ট্যাকার',
        'brand_id' => $brand->id,
        'age_min_months' => 12,
        'age_max_months' => 36,
        'specifications' => ['Material' => 'Beech wood'],
        'description' => '<p>Call 01711000222 to order.</p><p>Seven stacking rings.</p>',
    ]);
    $colour = ProductAttribute::factory()->create(['name' => 'Colour', 'name_bn' => 'রং']);
    $variant = $product->variants()->firstOrFail();
    $red = ProductAttributeValue::factory()->for($colour, 'attribute')->create(['value' => 'Red', 'value_bn' => 'লাল']);
    $variant->attributeValues()->attach($red->id, ['attribute_id' => $colour->id]);

    return $product;
}

function aiCopyUrl(Product $product): string
{
    return "/api/v1/admin/products/{$product->id}/ai-copy";
}

/** @return array<string, string> */
function aiCopyAnswer(array $override = []): array
{
    return $override + [
        'title' => 'Rainbow Stacker — Beech Wood Stacking Rings',
        'description_html' => '<p>A <strong>calm</strong> first toy.</p><h2>Why</h2><ul><li onclick="x()">Beech wood</li></ul><script>alert(1)</script><a href="https://x.test">link</a>',
        'seo_title' => 'Rainbow Stacker in Beech Wood | Smart Junior',
        'meta_description' => 'A beech wood rainbow stacker for 12 months to 3 years, ৳1,250.',
    ];
}

it('drafts English copy from the product’s own facts, sanitised, and saves nothing', function (): void {
    $user = Support::actingAs(Role::SuperAdmin);
    $product = aiCopyProduct();
    $other = Product::factory()->create(['name' => 'Secret Other Product']);
    $before = $product->fresh()->toArray();
    $this->fake->respond(['en' => aiCopyAnswer()]);

    $res = $this->postJson(aiCopyUrl($product), ['fields' => ['title', 'description', 'seo'], 'language' => 'en', 'tone' => 'warm', 'keywords' => 'wooden toy'])->assertOk()
        ->assertJsonPath('bn', null)
        ->assertJsonPath('model', 'claude-opus-5-5')
        ->assertJsonPath('en.title', 'Rainbow Stacker — Beech Wood Stacking Rings')
        ->assertJsonPath('en.description_html', '<p>A <strong>calm</strong> first toy.</p>Why<ul><li>Beech wood</li></ul>link')
        ->assertJsonPath('en.too_long', [])
        ->assertJsonPath('en.unknown_figures', []);

    $facts = collect($res->json('facts'))->pluck('value', 'key');
    expect($facts['name'])->toBe('Rainbow Stacker')
        ->and($facts['brand'])->toBe('Wonder Wood / ওয়ান্ডার উড')
        ->and($facts['age'])->toBe('12 months to 3 years')
        ->and($facts['spec'])->toBe('Material: Beech wood')
        ->and($facts['option'])->toBe('Colour / রং: Red / লাল')
        ->and($facts['price'])->toBe('৳1,250');

    $sent = $this->fake->sent[0]['prompt'];
    expect($sent->user)->toContain('Rainbow Stacker')->toContain('Beech wood')->toContain('wooden toy')
        ->not->toContain('01711000222')       // redacted
        ->not->toContain('Secret Other Product')
        ->not->toContain('800')               // cost price never leaves
        ->not->toContain($product->variants()->firstOrFail()->sku)
        ->and($sent->system)->toContain('Never invent safety claims')
        ->and($sent->schema['required'])->toBe(['en'])
        ->and($sent->schema['properties']['en']['required'])->toBe(['title', 'description_html', 'seo_title', 'meta_description'])
        ->and($sent->schema['properties']['en']['additionalProperties'])->toBeFalse();

    expect($product->fresh()->toArray())->toBe($before)
        ->and(AiRequest::query()->sole()->user_id)->toBe($user->id)
        ->and($other->exists)->toBeTrue();
});

it('writes only the fields asked for, and Bangla with Western digits', function (): void {
    Support::actingAs(Role::Manager);
    $product = aiCopyProduct();
    $this->fake->respond(['en' => ['title' => 'Rainbow Stacker'], 'bn' => ['title' => 'রংধনু স্ট্যাকার — ১২ মাস থেকে']]);

    $this->postJson(aiCopyUrl($product), ['fields' => ['title'], 'language' => 'both', 'tone' => 'playful'])->assertOk()
        ->assertJsonPath('en.title', 'Rainbow Stacker')
        ->assertJsonPath('en.description_html', null)
        ->assertJsonPath('en.seo_title', null)
        ->assertJsonPath('bn.title', 'রংধনু স্ট্যাকার — 12 মাস থেকে');
    expect($this->fake->sent[0]['prompt']->schema['required'])->toBe(['en', 'bn']);
});

it('sends an over-long or invented answer back once, then flags what is still wrong', function (): void {
    Support::actingAs(Role::SuperAdmin);
    $product = aiCopyProduct();
    $long = str_repeat('Lovely wooden rainbow stacker ', 4);
    $this->fake->respond(
        ['en' => ['title' => $long, 'seo_title' => 'Stacker', 'meta_description' => 'Holds 250 kg safely.']],
        ['en' => ['title' => 'Rainbow Stacker', 'seo_title' => 'Stacker', 'meta_description' => 'Tested to 250 kg.']],
    );

    $this->postJson(aiCopyUrl($product), ['fields' => ['title', 'seo'], 'language' => 'en', 'tone' => 'premium'])->assertOk()
        ->assertJsonPath('en.title', 'Rainbow Stacker')
        ->assertJsonPath('en.too_long', [])
        ->assertJsonPath('en.unknown_figures', ['250']);

    $retry = $this->fake->sent[1]['prompt']->user;
    expect($this->fake->sent)->toHaveCount(2)
        ->and($retry)->toContain('en.title is 119 characters; it must be at most 70')
        ->and($retry)->toContain('"250", which is not in the facts')
        ->and(AiRequest::query()->count())->toBe(2);
});

it('returns a still-too-long field flagged, never cut', function (): void {
    Support::actingAs(Role::SuperAdmin);
    $product = aiCopyProduct();
    $long = str_repeat('Lovely wooden rainbow stacker ', 3);
    $this->fake->respond(['en' => ['title' => $long]], ['en' => ['title' => $long]]);

    $this->postJson(aiCopyUrl($product), ['fields' => ['title'], 'language' => 'en', 'tone' => 'warm'])->assertOk()
        ->assertJsonPath('en.title', trim($long))
        ->assertJsonPath('en.too_long', ['title']);
});

it('does not retry a clean answer', function (): void {
    Support::actingAs(Role::SuperAdmin);
    $this->fake->respond(['en' => aiCopyAnswer()]);
    $this->postJson(aiCopyUrl(aiCopyProduct()), ['fields' => ['seo'], 'language' => 'en', 'tone' => 'warm'])->assertOk();
    expect($this->fake->sent)->toHaveCount(1);
});

it('refuses with a clear problem when product details are not shared with AI, without calling a provider', function (): void {
    Support::actingAs(Role::SuperAdmin);
    Setting::put('ai.share', ['figures' => true, 'products' => false, 'first_names' => true], SettingGroup::Ai);

    $this->postJson(aiCopyUrl(aiCopyProduct()), ['fields' => ['title'], 'language' => 'en', 'tone' => 'warm'])
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/ai-not-shared')
        ->assertJsonPath('detail', 'Product details are not shared with AI. Turn on “Product details” in Integrations › AI assistant to write with AI.');
    expect($this->fake->sent)->toBe([]);
});

it('maps AI failures to RFC 9457 problems', function (): void {
    Support::actingAs(Role::SuperAdmin);
    $product = aiCopyProduct();
    $this->fake->respond(new AiException(AiException::RATE_LIMITED, 'The provider is busy.'));
    $this->postJson(aiCopyUrl($product), ['fields' => ['title'], 'language' => 'en', 'tone' => 'warm'])
        ->assertStatus(503)->assertJsonPath('type', 'https://toymartbd.com/problems/ai-unavailable');

    $this->fake->respond(['en' => ['title' => '']]);
    $this->postJson(aiCopyUrl($product), ['fields' => ['title'], 'language' => 'en', 'tone' => 'warm'])
        ->assertStatus(503)->assertJsonPath('errors.0.message', 'invalid_output');

    Setting::query()->whereKey('ai.key.anthropic')->delete();
    $this->postJson(aiCopyUrl($product), ['fields' => ['title'], 'language' => 'en', 'tone' => 'warm'])
        ->assertStatus(409)->assertJsonPath('type', 'https://toymartbd.com/problems/ai-not-configured');
});

it('validates the request', function (): void {
    Support::actingAs(Role::SuperAdmin);
    $product = aiCopyProduct();

    $this->postJson(aiCopyUrl($product), ['fields' => [], 'language' => 'fr', 'tone' => 'angry', 'keywords' => str_repeat('a', 121)])
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/validation-error');
    $this->postJson(aiCopyUrl($product), ['fields' => ['price'], 'language' => 'en', 'tone' => 'warm'])->assertStatus(422);
    expect($this->fake->sent)->toBe([]);
});

describe('role matrix', function (): void {
    it('guards POST /products/{product}/ai-copy with catalog.update', function (Role $role, bool $allowed): void {
        $product = aiCopyProduct();
        RoleMatrix::assertFor($this, $role, $allowed, function () use ($product) {
            $this->fake->respond(['en' => ['title' => 'Rainbow Stacker']]);

            return $this->postJson(aiCopyUrl($product), ['fields' => ['title'], 'language' => 'en', 'tone' => 'warm']);
        });
    })->with(RoleMatrix::dataset(Permission::CatalogUpdate));

    it('guards GET /products/{product}/ai-copy/facts with catalog.update', function (Role $role, bool $allowed): void {
        $product = aiCopyProduct();
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(aiCopyUrl($product).'/facts'));
    })->with(RoleMatrix::dataset(Permission::CatalogUpdate));

    it('refuses a viewer with 403 and a guest with 401', function (): void {
        $product = aiCopyProduct();
        $this->postJson(aiCopyUrl($product), ['fields' => ['title'], 'language' => 'en', 'tone' => 'warm'])->assertUnauthorized();
        Support::actingAs(Role::Viewer);
        $this->postJson(aiCopyUrl($product), ['fields' => ['title'], 'language' => 'en', 'tone' => 'warm'])->assertForbidden();
        expect($this->fake->sent)->toBe([]);
    });
});

it('never sends archived variants’ options or another product’s data', function (): void {
    Support::actingAs(Role::SuperAdmin);
    $product = aiCopyProduct();
    ProductVariant::factory()->archived()->for($product)->create(['price' => '9999.00']);
    $this->fake->respond(['en' => ['title' => 'Rainbow Stacker']]);

    $this->postJson(aiCopyUrl($product), ['fields' => ['title'], 'language' => 'en', 'tone' => 'warm'])->assertOk();
    expect($this->fake->sent[0]['prompt']->user)->not->toContain('9,999')->not->toContain('9999');
});

it('lists the facts a draft would use without calling the AI', function (): void {
    Support::actingAs(Role::SuperAdmin);
    $product = aiCopyProduct();

    $facts = collect($this->getJson(aiCopyUrl($product).'/facts')->assertOk()->json('facts'));

    expect($facts->pluck('key'))->toContain('name', 'price')
        ->and($this->fake->sent)->toBe([])
        ->and(AiRequest::query()->count())->toBe(0);
});
