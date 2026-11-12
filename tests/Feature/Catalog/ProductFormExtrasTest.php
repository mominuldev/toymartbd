<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\Margins;
use App\Domain\Catalog\Services\MediaUrls;
use App\Models\Enums\Role;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * Product form extras (P4 batch 5): sales channels, VAT and server-computed margin,
 * BSTI compliance and its certificate PDF, stock by location, relation thumbnails.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '', 'media.cdn_url' => null]);
    Storage::fake('public');
    Storage::fake('local');
    Storage::fake(MediaUrls::originalsDisk());

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Manager)->create()->refresh(), 'web');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function extrasDocument(Product $product, array $overrides = []): array
{
    return [
        'name' => $product->name,
        'name_bn' => $product->name_bn,
        'type' => 'simple',
        'status' => $product->status->value,
        'primary_category_id' => $product->primary_category_id,
        'category_ids' => [$product->primary_category_id],
        'variants' => [['id' => $product->variants()->value('id'), 'price' => '2450.00', 'cost_price' => '1600.00']],
        ...$overrides,
    ];
}

function certificatePdf(string $body = 'BT /F1 12 Tf (BSTI certificate) Tj ET'): UploadedFile
{
    $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\n"
        .'3 0 obj << /Length '.strlen($body)." >> stream\n{$body}\nendstream endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    return UploadedFile::fake()->createWithContent('BSTI Certificate.pdf', $pdf);
}

it('computes margins on the server: VAT out, half away from zero, none without a cost', function (): void {
    $margins = new Margins;

    expect($margins->forUnit(Money::of('1850'), Money::of('1120'))?->toArray())
        ->toBe(['percent' => '39.5', 'profit' => '730.00', 'net_price' => '1850.00'])
        ->and($margins->forUnit(Money::of('2450'), Money::of('1600'), '15')?->toArray())
        ->toBe(['percent' => '24.9', 'profit' => '530.43', 'net_price' => '2130.43'])
        ->and($margins->forUnit(Money::of('500'), Money::of('600'))?->toArray()['percent'])->toBe('-20.0')
        ->and($margins->forUnit(Money::of('500'), Money::zero()))->toBeNull()
        ->and($margins->forUnit(Money::zero(), Money::of('100')))->toBeNull();
});

it('saves sales channels, VAT and compliance with the document and returns the margin', function (): void {
    $product = Product::factory()->create();

    $this->putJson("/api/v1/admin/products/{$product->id}", extrasDocument($product, [
        'sales_channels' => ['online_store' => true, 'pos' => false, 'facebook_instagram' => true, 'google_merchant' => false],
        'vat_rate' => '15',
        'compliance' => ['bsti_certificate_no' => 'BSTI/CM/2026-0412', 'bsti_expires_on' => '2027-03-31'],
    ]))
        ->assertOk()
        ->assertJsonPath('sales_channels', ['online_store' => true, 'pos' => false, 'facebook_instagram' => true, 'google_merchant' => false])
        ->assertJsonPath('vat_rate', '15.00')
        ->assertJsonPath('compliance.bsti_certificate_no', 'BSTI/CM/2026-0412')
        ->assertJsonPath('compliance.bsti_expires_on', '2027-03-31')
        ->assertJsonPath('compliance.expired', false)
        ->assertJsonPath('compliance.certificate', null)
        ->assertJsonPath('margin', ['percent' => '24.9', 'profit' => '530.43', 'net_price' => '2130.43'])
        ->assertJsonPath('variants.0.margin.percent', '24.9')
        ->assertJsonPath('margin_lowest.percent', '24.9');
});

it('rejects a VAT rate above 100 and a malformed certificate number', function (): void {
    $product = Product::factory()->create();

    $this->putJson("/api/v1/admin/products/{$product->id}", extrasDocument($product, ['vat_rate' => '120']))
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'vat_rate');

    $this->putJson("/api/v1/admin/products/{$product->id}", extrasDocument($product, ['compliance' => ['bsti_certificate_no' => '<script>', 'bsti_expires_on' => null]]))
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'compliance.bsti_certificate_no');
});

it('flags an expired BSTI certificate by the Dhaka date', function (): void {
    $product = Product::factory()->create(['bsti_certificate_no' => 'CM-1', 'bsti_expires_on' => now('Asia/Dhaka')->subDay()->toDateString()]);

    $this->getJson("/api/v1/admin/products/{$product->id}")->assertOk()->assertJsonPath('compliance.expired', true);
});

it('hides every margin from staff without catalog.view_cost', function (): void {
    $product = Product::factory()->create();
    auth()->forgetGuards();
    $this->actingAs(User::factory()->withRole(Role::ContentEditor)->create()->refresh(), 'web');

    $json = $this->getJson("/api/v1/admin/products/{$product->id}")->assertOk()->json();

    expect($json)->not->toHaveKey('margin')->not->toHaveKey('margin_lowest')
        ->and($json['variants'][0])->not->toHaveKey('margin');

    $this->postJson('/api/v1/admin/products/margin-preview', ['rows' => [['price' => '100', 'cost_price' => '50']]])->assertForbidden();
});

it('previews margins for unsaved rows', function (): void {
    $this->postJson('/api/v1/admin/products/margin-preview', [
        'vat_rate' => '0',
        'rows' => [['price' => '1850', 'cost_price' => '1120'], ['price' => '1850', 'cost_price' => null]],
    ])
        ->assertOk()
        ->assertJsonPath('rows.0', ['percent' => '39.5', 'profit' => '730.00', 'net_price' => '1850.00'])
        ->assertJsonPath('rows.1', null);

    $this->postJson('/api/v1/admin/products/margin-preview', ['rows' => [['price' => 'abc']]])->assertUnprocessable();
});

it('lists stock per location for the product, zeros included', function (): void {
    $product = Product::factory()->create();
    $variant = ProductVariant::query()->where('product_id', $product->id)->sole();
    $main = (int) DB::table('stock_locations')->where('is_default', true)->value('id');
    $branch = DB::table('stock_locations')->insertGetId([
        'code' => 'MIR', 'name' => 'Mirpur branch', 'name_bn' => 'মিরপুর শাখা', 'is_active' => true, 'is_default' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('stock_levels')->insert(['variant_id' => $variant->id, 'location_id' => $main, 'on_hand' => 12, 'reserved' => 3, 'created_at' => now(), 'updated_at' => now()]);

    $rows = collect($this->getJson("/api/v1/admin/products/{$product->id}")->assertOk()->json('stock_by_location'))->keyBy('location_id');

    expect($rows[$main])->toMatchArray(['is_default' => true, 'on_hand' => 12, 'reserved' => 3, 'available' => 9])
        ->and($rows[$branch])->toMatchArray(['name_bn' => 'মিরপুর শাখা', 'on_hand' => 0, 'reserved' => 0, 'available' => 0]);
});

it('uploads, downloads and removes a BSTI certificate PDF', function (): void {
    $product = Product::factory()->create();

    $this->post("/api/v1/admin/products/{$product->id}/compliance/certificate", ['file' => certificatePdf()], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('certificate.name', 'BSTI-Certificate.pdf');

    $key = (string) $product->refresh()->bsti_certificate_path;
    Storage::disk(MediaUrls::originalsDisk())->assertExists($key);

    $download = $this->get("/api/v1/admin/products/{$product->id}/compliance/certificate");
    $download->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($download->headers->get('Content-Disposition'))->toContain('attachment');

    $this->deleteJson("/api/v1/admin/products/{$product->id}/compliance/certificate")
        ->assertOk()->assertJsonPath('certificate', null);
    $this->getJson("/api/v1/admin/products/{$product->id}/compliance/certificate")->assertNotFound();
});

it('refuses a certificate that is not a PDF by content or carries scripts', function (): void {
    $product = Product::factory()->create();

    $fake = UploadedFile::fake()->createWithContent('cert.pdf', 'just text pretending');
    $this->post("/api/v1/admin/products/{$product->id}/compliance/certificate", ['file' => $fake], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'file');

    $this->post("/api/v1/admin/products/{$product->id}/compliance/certificate", ['file' => certificatePdf('/JavaScript (app.alert(1))')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'file');

    expect($product->refresh()->bsti_certificate_path)->toBeNull();
});

it('returns a thumbnail and a price on each recommendation', function (): void {
    $product = Product::factory()->create();
    $target = Product::factory()->priced('3200.00')->create();

    $this->putJson("/api/v1/admin/products/{$product->id}", extrasDocument($product, [
        'relations' => [['related_product_id' => $target->id, 'type' => 'cross_sell']],
    ]))->assertOk();

    $related = $this->getJson("/api/v1/admin/products/{$product->id}")->assertOk()->json('relations.0.related_product');

    expect($related)->toHaveKeys(['image_url', 'price'])
        ->and($related['price'])->toBe('3200.00')
        ->and($related['image_url'])->toBeNull();
});
