<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\IssueSalesInvoice;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\PrintSettings;
use App\Domain\Invoicing\Services\SampleInvoice;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Http\Controllers\Api\V1\Admin\OrderInvoicePdf;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Tests\Feature\Orders\Support;
use Tests\Support\PdfText;
use Tests\Support\RoleMatrix;

/*
 * Settings › Invoices, receipts & labels › A4 invoice (Figma 528:81351): the real customer
 * invoice follows the saved choices — paper, language, colour, optional parts — without touching
 * the issued snapshot, and the settings page previews a sample invoice, draft included.
 * Assertions read the HTML handed to mPDF (PDF bytes are compressed) and, where it matters, the
 * PDF itself.
 */

const INVOICE_PREVIEW_URL = '/api/v1/admin/settings/print/invoice-preview';

/** @param  array<string, string|bool>  $changes */
function invoicePrint(array $changes = []): array
{
    return [...PrintSettings::DEFAULTS['invoice'], ...$changes];
}

/** The sample invoice's template HTML under the given settings. */
function sampleInvoiceHtml(array $changes = [], ?string $locale = null): string
{
    $print = invoicePrint($changes);

    return app(OrderInvoicePdf::class)->html([['invoice' => app(SampleInvoice::class)->make(), 'locale' => $locale ?? (string) $print['language']]], $print);
}

function printedOrder(): Order
{
    $order = Order::factory()->create(['grand_total' => '2450.00', 'subtotal' => '2380.00', 'shipping_charge' => '70.00', 'due_total' => '2450.00', 'shipping_landmark' => 'জামে মসজিদের পাশে']);
    OrderItem::factory()->create(['order_id' => $order->id, 'quantity' => 2, 'unit_price' => '1190.00', 'line_total' => '2380.00', 'sku' => 'TMB-RC-01', 'variant_name' => 'Red · 1:16']);

    return $order->refresh();
}

it('prints every optional part by default, in brand colour, with the order barcode and track QR', function (): void {
    $html = sampleInvoiceHtml();

    expect($html)->toContain('<barcode code="'.SampleInvoice::ORDER.'" type="C128B"')
        ->toContain('class="track-qr" src="'.OrderInvoicePdf::qrDataUri(OrderInvoicePdf::trackUrl(SampleInvoice::ORDER, false)).'"')
        ->and(OrderInvoicePdf::trackUrl(SampleInvoice::ORDER, false))->toBe('https://'.config('notifications.store_url').'/en/track?order='.SampleInvoice::ORDER)
        ->and(OrderInvoicePdf::trackUrl(SampleInvoice::ORDER, true))->toBe('https://'.config('notifications.store_url').'/track?order='.SampleInvoice::ORDER)
        ->and($html)
        ->toContain('TMB-RC-01')->toContain('Red · 1:16')
        ->toContain('#4F46E5')
        ->toContain('BIN')
        ->toContain('Cash on delivery · ৳6,260 due')
        ->toContain('Received by (customer)')->toContain('Authorised signature')
        // Email is off by Figma default.
        ->not->toContain('rahela@example.com');
});

it('leaves out each part the owner switches off', function (string $toggle, string $gone): void {
    expect(sampleInvoiceHtml())->toContain($gone)
        ->and(sampleInvoiceHtml([$toggle => false]))->not->toContain($gone);
})->with([
    'BIN line' => ['show_bin', 'Trade licence'],
    'order barcode' => ['show_barcode', 'type="C128B"'],
    'customer phone' => ['show_phone', 'Phone:'],
    'SKU column' => ['show_sku', 'TMB-BB-500'],
    'product options' => ['show_options', 'Classic colours'],
    'payment stamp' => ['show_payment_stamp', '৳6,260 due'],
    'track QR' => ['show_track_qr', 'class="track-qr"'],
    'signature lines' => ['show_signatures', 'Authorised signature'],
]);

it('prints the customer email only when switched on', function (): void {
    expect(sampleInvoiceHtml(['show_email' => true]))->toContain('rahela@example.com');
});

it('prints black and white without the brand colour', function (): void {
    expect(sampleInvoiceHtml(['colour' => 'mono']))->not->toContain('#4F46E5');
});

it('stamps a fully paid invoice "Paid" from the stored due, not a sum', function (): void {
    $invoice = app(SampleInvoice::class)->make();
    $invoice->forceFill(['paid' => '6260.00', 'due' => '0.00']);
    $html = app(OrderInvoicePdf::class)->html([['invoice' => $invoice, 'locale' => 'en']], invoicePrint());

    expect($html)->toContain('class="stamp">Paid<')->not->toContain('6,260 due');
});

it('prints in Bangla with Western digits, and both languages with Bangla labels beneath', function (): void {
    $bn = sampleInvoiceHtml(['language' => 'bn']);
    $both = sampleInvoiceHtml(['language' => 'both']);

    expect($bn)->toContain('ইনভয়েস')->toContain('ক্যাশ অন ডেলিভারি · ৳6,260 বাকি')->toContain(OrderInvoicePdf::qrDataUri(OrderInvoicePdf::trackUrl(SampleInvoice::ORDER, true)))
        ->not->toMatch('/[০-৯]/u')
        ->and($both)->toContain('Invoice<br><span class="sub lang-bn" lang="bn">ইনভয়েস</span>')
        ->toContain('Subtotal<br><span class="sub lang-bn" lang="bn">সাবটোটাল</span>')
        ->toContain('Remote control racing car');
});

it('picks A5 for customer invoices and keeps A4 otherwise', function (): void {
    $sales = [['invoice' => app(SampleInvoice::class)->make(), 'locale' => 'en']];

    expect(OrderInvoicePdf::format($sales, invoicePrint(['paper' => 'a5'])))->toBe('A5')
        ->and(OrderInvoicePdf::format($sales, invoicePrint()))->toBe('A4');
});

it('prints a real order on the saved settings without touching its snapshot', function (): void {
    Support::actingAs(Role::Manager);
    Setting::put('print.invoice', invoicePrint(['paper' => 'a5', 'language' => 'bn', 'show_sku' => false]), SettingGroup::Store);
    $order = printedOrder();

    $pdf = (string) $this->get("/api/v1/admin/orders/{$order->id}/invoice")->assertOk()->getContent();
    $invoice = Invoice::query()->sole();
    $text = PdfText::extract($pdf);

    // A5 is 148 × 210 mm = 419.53 × 595.28 pt.
    expect($pdf)->toMatch('/MediaBox \[0 0 419\.5\d* 595\.2\d*\]/')
        ->and(PdfText::banglaLetters($text))->toContain(PdfText::banglaLetters('ইনভয়েস'))
        ->and($text)->not->toContain('TMB-RC-01')
        ->and($invoice->lines[0]['sku'])->toBe('TMB-RC-01');

    // An explicit ?locale= still wins over the setting.
    $en = PdfText::extract((string) $this->get("/api/v1/admin/orders/{$order->id}/invoice?locale=en")->assertOk()->getContent());
    expect($en)->toContain('Invoice');
});

it('snapshots the customer email on new invoices and hides it without PII rights', function (): void {
    $invoice = app(IssueSalesInvoice::class)->handle(printedOrder());
    expect($invoice->issued_to)->toHaveKey('email');

    Support::actingAs(Role::Viewer);
    expect($this->getJson("/api/v1/admin/invoices/{$invoice->id}")->assertOk()->json('issued_to'))->not->toHaveKey('email');
});

it('previews the sample invoice on the saved settings, overridden by the draft', function (): void {
    Support::actingAs(Role::Manager);
    Setting::put('print.invoice', invoicePrint(['language' => 'bn']), SettingGroup::Store);

    $saved = $this->get(INVOICE_PREVIEW_URL)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(PdfText::banglaLetters(PdfText::extract((string) $saved->getContent())))->toContain(PdfText::banglaLetters('নমুনা ইনভয়েস'));

    $draft = $this->get(INVOICE_PREVIEW_URL.'?language=en&paper=a5&show_sku=0')->assertOk();
    $text = PdfText::extract((string) $draft->getContent());
    expect($text)->toContain(SampleInvoice::NUMBER)->toContain('Sample invoice')->not->toContain('TMB-RC-01')
        ->and((string) $draft->getContent())->toMatch('/MediaBox \[0 0 419\.5\d* 595\.2\d*\]/')
        ->and(Invoice::query()->count())->toBe(0);

    // The track QR is drawn into the PDF (an SVG form object), and gone when switched off.
    expect(substr_count((string) $draft->getContent(), '/Subtype /Form'))->toBe(1)
        ->and(substr_count((string) $this->get(INVOICE_PREVIEW_URL.'?show_track_qr=0')->assertOk()->getContent(), '/Subtype /Form'))->toBe(0);
});

it('rejects preview parameters outside the allow-list', function (string $query, string $field): void {
    Support::actingAs(Role::Manager);
    $this->getJson(INVOICE_PREVIEW_URL.'?'.$query)->assertStatus(422)->assertJsonPath('errors.0.field', $field);
})->with([
    'paper' => ['paper=letter', 'paper'],
    'toggle' => ['show_sku=yes', 'show_sku'],
    'unknown' => ['show_logo_big=1', 'show_logo_big'],
]);

describe('role matrix', function (): void {
    it('guards GET /settings/print/invoice-preview with settings.view', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->get(INVOICE_PREVIEW_URL, ['Accept' => 'application/json']));
    })->with(RoleMatrix::dataset(Permission::SettingsView));

    it('refuses a guest with 401', function (): void {
        $this->getJson(INVOICE_PREVIEW_URL)->assertUnauthorized();
    });
});
