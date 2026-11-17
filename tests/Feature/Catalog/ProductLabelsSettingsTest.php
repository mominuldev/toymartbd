<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\LabelTemplate;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\Ean13;
use App\Domain\Invoicing\Services\PrintSettings;
use App\Models\Enums\Role;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\PdfText;

/*
 * POST /products/labels honours Settings › Invoices, receipts & labels › Barcode labels
 * (owner request 2026-11-17): an omitted field takes the saved setting, a sent one wins, and
 * each of the show_* toggles reaches the printed label.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '', 'catalog.barcode.prefix' => '20']);

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Manager)->create()->refresh(), 'web');
});

/** @param  array<string, mixed>  $attributes */
function settingsLabelVariant(array $attributes = []): ProductVariant
{
    static $n = 0;
    $n++;
    $owner = Product::factory()->variable()->create(['name' => 'Kids Tricycle', 'name_bn' => 'বাচ্চাদের ট্রাইসাইকেল']);

    return ProductVariant::factory()->for($owner)->create($attributes + ['sku' => 'TRI-RED-'.$n, 'barcode' => Ean13::build('21', 4_000 + $n), 'name' => 'Red', 'name_bn' => 'লাল', 'price' => '4900.00']);
}

/** @param  array<string, mixed>  $labels */
function saveLabelSettings(array $labels): void
{
    Setting::put(PrintSettings::PREFIX.'labels', PrintSettings::clean('labels', [...PrintSettings::DEFAULTS['labels'], ...$labels]), SettingGroup::Store, false, null);
}

/** @param  array<string, mixed>  $body */
function printSettingsLabels(array $body): TestResponse
{
    return test()->postJson('/api/v1/admin/products/labels', $body);
}

function labelText(TestResponse $response): string
{
    return PdfText::extract((string) $response->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent());
}

function pageCount(TestResponse $response): int
{
    return (int) preg_match_all('/\/Type\s*\/Page\b/', (string) $response->getContent());
}

it('prints with the Figma defaults when nothing is saved and nothing but items is sent', function (): void {
    $variant = settingsLabelVariant();

    $response = printSettingsLabels(['items' => [['variant_id' => $variant->id, 'copies' => 2]]]);
    $text = labelText($response);

    // roll_50x25: one label per page; store, name, options, price and digits all on.
    expect(pageCount($response))->toBe(2)
        ->and($text)->toContain('TOY MART BD')
        ->and($text)->toContain('Kids Tricycle')
        ->and($text)->toContain('Red')
        ->and($text)->toContain('4,900')->and($text)->toContain('৳')
        ->and($text)->toContain(substr($variant->barcode, 1, 6));
});

it('takes the template and every toggle from the saved settings', function (): void {
    saveLabelSettings(['template' => 'a4_24', 'show_name' => false, 'show_options' => false, 'show_price' => false, 'show_store' => false, 'show_digits' => false]);
    $variant = settingsLabelVariant();

    $response = printSettingsLabels(['items' => [['variant_id' => $variant->id, 'copies' => 3]]]);
    $text = labelText($response);

    expect(pageCount($response))->toBe(1)
        ->and($text)->not->toContain('TOY MART BD')
        ->and($text)->not->toContain('Kids Tricycle')
        ->and($text)->not->toContain('Red')
        ->and($text)->not->toContain('৳')
        ->and($text)->not->toContain(substr($variant->barcode, 1, 6))
        ->and($text)->toContain($variant->sku);
});

it('lets the request override the saved settings', function (): void {
    saveLabelSettings(['template' => 'a4_24', 'show_name' => false, 'show_store' => false, 'show_price' => false]);
    $variant = settingsLabelVariant();

    $response = printSettingsLabels(['template' => 'roll_38x25', 'show_name' => true, 'show_store' => true, 'show_price' => true, 'items' => [['variant_id' => $variant->id, 'copies' => 2]]]);
    $text = labelText($response);

    expect(pageCount($response))->toBe(2)
        ->and($text)->toContain('Kids Tricycle')
        ->and($text)->toContain('TOY MART BD')
        ->and($text)->toContain('4,900')->and($text)->toContain('৳');
});

it('leaves the options line off with show_options false and keeps the name', function (): void {
    $variant = settingsLabelVariant(['name' => 'Crimson / XL']);

    $text = labelText(printSettingsLabels(['show_options' => false, 'items' => [['variant_id' => $variant->id, 'copies' => 1]]]));

    expect($text)->toContain('Kids Tricycle')
        ->and($text)->not->toContain('Crimson / XL');
});

it('prints the saved store name in the label language', function (): void {
    Setting::put('invoice.company.name_en', 'Rainbow Toys', SettingGroup::Store, false, null);
    Setting::put('invoice.company.name_bn', 'রংধনু খেলনা', SettingGroup::Store, false, null);
    $variant = settingsLabelVariant();

    $en = labelText(printSettingsLabels(['show_store' => true, 'items' => [['variant_id' => $variant->id, 'copies' => 1]]]));
    $bn = labelText(printSettingsLabels(['show_store' => true, 'locale' => 'bn', 'items' => [['variant_id' => $variant->id, 'copies' => 1]]]));
    $off = labelText(printSettingsLabels(['show_store' => false, 'items' => [['variant_id' => $variant->id, 'copies' => 1]]]));

    expect($en)->toContain('RAINBOW TOYS')
        ->and(PdfText::banglaLetters($bn))->toContain(PdfText::banglaLetters('রংধনু খেলনা'))
        ->and($bn)->not->toContain('RAINBOW TOYS')
        ->and($off)->not->toContain('RAINBOW TOYS');
});

it('hides the digits under the bars with show_digits false, for EAN-13 and Code 128', function (): void {
    $ean = settingsLabelVariant();
    $code128 = settingsLabelVariant(['barcode' => 'TOY-TRI-77']);
    $items = [['variant_id' => $ean->id, 'copies' => 1], ['variant_id' => $code128->id, 'copies' => 1]];

    $on = printSettingsLabels(['show_digits' => true, 'items' => $items]);
    $off = printSettingsLabels(['show_digits' => false, 'items' => $items]);
    $onText = labelText($on);
    $offText = labelText($off);

    expect($onText)->toContain(substr($ean->barcode, 1, 6))
        ->and($onText)->toContain('TOY-TRI-77')
        ->and($offText)->not->toContain(substr($ean->barcode, 1, 6))
        ->and($offText)->not->toContain('TOY-TRI-77')
        // The EAN-13 still has its bars: drawn from the SVG, one label per page as before.
        ->and(pageCount($off))->toBe(2)
        ->and($offText)->toContain($ean->sku);
});

it('generates missing barcodes from the saved setting for someone with catalog.update', function (): void {
    $missing = settingsLabelVariant(['barcode' => null]);

    printSettingsLabels(['items' => [['variant_id' => $missing->id, 'copies' => 1]]])->assertOk();

    expect($missing->refresh()->barcode)->toBe('2000000000015');
});

it('does not generate when the saved setting is off', function (): void {
    saveLabelSettings(['generate_missing' => false]);
    $missing = settingsLabelVariant(['barcode' => null]);

    printSettingsLabels(['items' => [['variant_id' => $missing->id, 'copies' => 1]]])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.field', 'items.0.variant_id');

    expect($missing->refresh()->barcode)->toBeNull();
});

it('ignores the saved generate setting without catalog.update: a 422, never a 403', function (): void {
    $missing = settingsLabelVariant(['barcode' => null]);
    $printable = settingsLabelVariant();
    $this->actingAs(User::factory()->withRole(Role::OrderOperator)->create()->refresh(), 'web');

    printSettingsLabels(['items' => [['variant_id' => $missing->id, 'copies' => 1]]])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.field', 'items.0.variant_id');
    printSettingsLabels(['items' => [['variant_id' => $printable->id, 'copies' => 1]]])->assertOk();

    expect($missing->refresh()->barcode)->toBeNull();
});

it('rejects a non-boolean toggle', function (string $field): void {
    $variant = settingsLabelVariant();

    printSettingsLabels([$field => 'maybe', 'items' => [['variant_id' => $variant->id, 'copies' => 1]]])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.field', $field);
})->with(['show_options', 'show_store', 'show_digits']);

it('gives the barcode all the height that is left, so no label ends in a blank band', function (LabelTemplate $template, bool $store, bool $name, bool $price): void {
    $pdf = app(App\Http\Controllers\Api\V1\Admin\ProductLabelPdf::class);
    $mpdf = new Mpdf\Mpdf(['tempDir' => App\Support\MpdfCache::path(), 'fontDir' => [...(new Mpdf\Config\ConfigVariables)->getDefaults()['fontDir'], resource_path('fonts/invoice')], 'fontdata' => (new Mpdf\Config\FontVariables)->getDefaults()['fontdata'] + [App\Http\Controllers\Api\V1\Admin\OrderInvoicePdf::FONT => ['R' => 'HindSiliguri-Regular.ttf', 'B' => 'HindSiliguri-SemiBold.ttf']]]);
    $label = ['name' => 'Kids Tricycle with Push Handle and Bell', 'variant' => 'Red · 2–5 years', 'price' => '৳4,900', 'sku' => 'TRI-RED', 'barcode' => '2000000001173'];

    [$box] = $pdf->boxes($mpdf, $template, [$label], 'en', $price, $name, true, $store ? 'Toy Mart BD' : null, true);
    $style = App\Http\Controllers\Api\V1\Admin\ProductLabelPdf::style($template);
    $innerH = $template->labelSize()[1] - 2 * $style['padding'];
    $mm = 25.4 / 72;
    $head = max($style['store_pt'], $style['sku_pt']) * $mm * 1.15 + ($store ? 0.18 : 0.0);
    $blocks = 1 + ($store ? 1 : 0) + ($box['mid_height'] > 0 ? 1 : 0);
    $filled = $head + $box['mid_height'] + $style['gap'] * $blocks + $box['bar_mm'];

    expect($filled)->toBeGreaterThan($innerH - 0.3)->toBeLessThanOrEqual($innerH)
        ->and($box['bar_mm'])->toBeGreaterThanOrEqual($innerH * 0.38 - 0.01)
        ->and($box['store'])->toBe($store ? 'TOY MART BD' : null);
})->with(LabelTemplate::cases())->with([
    'everything' => [true, true, true],
    'no store' => [false, true, true],
    'bars and price only' => [false, false, true],
]);
