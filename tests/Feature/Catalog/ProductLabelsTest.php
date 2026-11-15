<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\LabelTemplate;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\Ean13;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\PdfText;

/*
 * Barcode label PDFs (owner request 2026-11-16): POST /products/labels and
 * GET /products/labels/templates.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '', 'catalog.barcode.prefix' => '20']);

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Manager)->create()->refresh(), 'web');
});

/**
 * A variant with a valid EAN-13 from prefix 21, so it never meets the generator's prefix 20.
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $product
 */
function labelVariant(array $attributes = [], array $product = []): ProductVariant
{
    static $n = 0;
    $n++;
    $owner = Product::factory()->variable()->create($product + ['name' => 'Wooden Train Set', 'name_bn' => 'কাঠের রেলগাড়ি সেট']);

    return ProductVariant::factory()->for($owner)->create($attributes + ['sku' => 'WTS-RED-'.$n, 'barcode' => Ean13::build('21', $n), 'name' => 'Red / Large', 'name_bn' => 'লাল / বড়', 'price' => '1250.00']);
}

/** @param  array<string, mixed>  $body */
function printLabels(array $body): TestResponse
{
    return test()->postJson('/api/v1/admin/products/labels', $body);
}

it('lists the templates with their size', function (): void {
    $this->getJson('/api/v1/admin/products/labels/templates')
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0', ['value' => 'roll_38x25', 'stock' => 'roll', 'width_mm' => '38', 'height_mm' => '25', 'per_page' => 1])
        ->assertJsonPath('data.2', ['value' => 'a4_65', 'stock' => 'a4', 'width_mm' => '38.1', 'height_mm' => '21.2', 'per_page' => 65])
        ->assertJsonPath('data.3', ['value' => 'a4_24', 'stock' => 'a4', 'width_mm' => '70', 'height_mm' => '37', 'per_page' => 24]);
});

it('renders a PDF for every template', function (LabelTemplate $template): void {
    $ean = labelVariant();
    $code128 = labelVariant(['sku' => 'WTS-BLUE', 'barcode' => 'TOY-BLUE-01', 'name' => null, 'name_bn' => null]);

    $response = printLabels(['template' => $template->value, 'items' => [['variant_id' => $ean->id, 'copies' => 2], ['variant_id' => $code128->id, 'copies' => 1]]])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $pdf = (string) $response->getContent();
    $text = PdfText::extract($pdf);
    expect($pdf)->toStartWith('%PDF')
        ->and(str_contains((string) $response->headers->get('Content-Disposition'), 'attachment; filename="labels-'))->toBeTrue()
        ->and($text)->toContain('Wooden Train Set')
        ->and($text)->toContain('Red / Large')
        ->and($text)->toContain('৳1,250')
        ->and($text)->toContain('WTS-RED')
        ->and($text)->toContain('TOY-BLUE-01')
        ->and(preg_match_all('/\/Type\s*\/Page\b/', $pdf))->toBe($template->isSheet() ? 1 : 3);
})->with(LabelTemplate::cases());

it('fills A4 sheets page by page', function (): void {
    $variant = labelVariant();

    $pdf = (string) printLabels(['template' => 'a4_24', 'items' => [['variant_id' => $variant->id, 'copies' => 25]]])->assertOk()->getContent();

    expect(preg_match_all('/\/Type\s*\/Page\b/', $pdf))->toBe(2);
});

it('prints the Bangla name, falling back to English', function (): void {
    $bangla = labelVariant();
    $english = labelVariant(['sku' => 'EN-ONLY', 'barcode' => '2000000000022', 'name' => null, 'name_bn' => null], ['name' => 'Puzzle Cube', 'name_bn' => null]);

    $pdf = (string) printLabels(['template' => 'roll_50x25', 'locale' => 'bn', 'items' => [['variant_id' => $bangla->id, 'copies' => 1], ['variant_id' => $english->id, 'copies' => 1]]])
        ->assertOk()->getContent();

    $text = PdfText::extract($pdf);
    expect($pdf)->toStartWith('%PDF')
        ->and(PdfText::banglaLetters($text))->toContain(PdfText::banglaLetters('কাঠের রেলগাড়ি সেট'))
        ->and($text)->not->toContain('Wooden Train Set')
        ->and($text)->toContain('Puzzle Cube');
});

it('leaves the name and the price off when asked', function (): void {
    $variant = labelVariant();

    $text = PdfText::extract((string) printLabels(['template' => 'roll_38x25', 'show_name' => false, 'show_price' => false, 'items' => [['variant_id' => $variant->id, 'copies' => 1]]])->assertOk()->getContent());

    expect($text)->not->toContain('Wooden Train Set')
        ->and($text)->not->toContain('৳')
        ->and($text)->toContain('WTS-RED');
});

it('cuts a long name with an ellipsis', function (): void {
    $variant = labelVariant([], ['name' => str_repeat('Very Long Educational Toy Name ', 8)]);

    $text = PdfText::extract((string) printLabels(['template' => 'roll_38x25', 'items' => [['variant_id' => $variant->id, 'copies' => 1]]])->assertOk()->getContent());

    expect($text)->toContain('…')
        ->and(substr_count($text, 'Educational'))->toBeLessThan(4);
});

it('is a 422 listing the variants without a barcode', function (): void {
    $ok = labelVariant();
    $missing = labelVariant(['sku' => 'NO-CODE', 'barcode' => null]);

    printLabels(['template' => 'a4_65', 'items' => [['variant_id' => $ok->id, 'copies' => 1], ['variant_id' => $missing->id, 'copies' => 1]]])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.field', 'items.1.variant_id');

    expect($missing->refresh()->barcode)->toBeNull();
});

it('generates the missing barcodes first with generate_missing', function (): void {
    $missing = labelVariant(['sku' => 'NO-CODE', 'barcode' => null]);
    $typed = labelVariant(['sku' => 'TYPED', 'barcode' => '8901234567890']);

    $text = PdfText::extract((string) printLabels(['template' => 'roll_38x25', 'generate_missing' => true, 'items' => [['variant_id' => $missing->id, 'copies' => 1], ['variant_id' => $typed->id, 'copies' => 1]]])
        ->assertOk()->getContent());

    expect($missing->refresh()->barcode)->toBe('2000000000015')
        ->and($typed->refresh()->barcode)->toBe('8901234567890')
        ->and($text)->toContain('NO-CODE');
});

it('refuses generate_missing without catalog.update (403) and writes nothing', function (): void {
    $missing = labelVariant(['barcode' => null]);
    $this->actingAs(User::factory()->withRole(Role::OrderOperator)->create()->refresh(), 'web');

    printLabels(['template' => 'roll_38x25', 'generate_missing' => true, 'items' => [['variant_id' => $missing->id, 'copies' => 1]]])->assertForbidden();

    expect($missing->refresh()->barcode)->toBeNull();
});

it('enforces the caps on copies, items and total labels', function (array $body, string $field): void {
    $variant = labelVariant();
    $items = static fn (int $count, int $copies): array => array_map(static fn (int $i): array => ['variant_id' => $variant->id + $i, 'copies' => $copies], range(0, $count - 1));
    $body['items'] = match ($body['items']) {
        'zero-copies' => [['variant_id' => $variant->id, 'copies' => 0]],
        'too-many-copies' => [['variant_id' => $variant->id, 'copies' => 501]],
        'too-many-items' => $items(301, 1),
        'empty' => [],
        default => $body['items'],
    };

    printLabels(['template' => 'roll_38x25'] + $body)->assertUnprocessable()->assertJsonPath('errors.0.field', $field);
})->with([
    'copies 0' => [['items' => 'zero-copies'], 'items.0.copies'],
    'copies 501' => [['items' => 'too-many-copies'], 'items.0.copies'],
    '301 items' => [['items' => 'too-many-items'], 'items'],
    'no items' => [['items' => 'empty'], 'items'],
]);

it('caps the total at 5,000 labels', function (): void {
    $variants = collect(range(1, 11))->map(fn (int $i): ProductVariant => labelVariant(['sku' => "CAP-{$i}", 'barcode' => "CAP-{$i}"]));
    $items = $variants->map(fn (ProductVariant $v): array => ['variant_id' => $v->id, 'copies' => 500])->all();

    printLabels(['template' => 'a4_65', 'items' => $items])->assertUnprocessable()->assertJsonPath('errors.0.field', 'items');
});

it('accepts only its own fields and templates', function (array $body, string $field): void {
    $variant = labelVariant();

    printLabels($body + ['template' => 'roll_38x25', 'items' => [['variant_id' => $variant->id, 'copies' => 1]]])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.field', $field);
})->with([
    'unknown template' => [['template' => 'a4_99'], 'template'],
    'unknown field' => [['price' => '1.00'], 'price'],
    'unknown item field' => [['items' => [['variant_id' => 1, 'copies' => 1, 'price' => '1']]], 'items.0'],
    'bad locale' => [['locale' => 'fr'], 'locale'],
    'unknown variant' => [['items' => [['variant_id' => 999_999, 'copies' => 1]]], 'items.0.variant_id'],
]);
