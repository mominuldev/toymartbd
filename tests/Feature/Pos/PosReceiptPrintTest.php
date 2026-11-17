<?php

declare(strict_types=1);

use App\Domain\Invoicing\Services\CompanyDetails;
use App\Domain\Invoicing\Services\PrintSettings;
use App\Domain\Invoicing\Services\ReceiptCodes;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Pos\PosKit;

/*
 * The counter receipt carries the owner's Thermal receipt settings (Settings › Invoices,
 * receipts & labels, Figma 529:81638) so the register — whose cashier may not hold
 * settings.view — prints the way the owner chose: paper, language, what shows, the receipt
 * barcode and the WhatsApp QR. The phone stays masked whatever is switched on (rule 20).
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    Mail::fake();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->branch = PosKit::branch();
    $this->cashier = PosKit::cashier();
    $this->session = PosKit::open($this->branch, $this->cashier);
    $this->brick = PosKit::variant($this->branch, 10, '1850.00', '1100.00');
});

function receiptFor(object $test): Illuminate\Testing\TestResponse
{
    $sale = PosKit::charge($test->session, [$test->brick->id => 1], [['method' => 'cash', 'amount' => '2000']], '1850.00', ['customer_phone' => PosKit::PHONE, 'customer_name' => 'Rubina Akter'])->assertCreated();

    return $test->getJson("/api/v1/admin/pos/sales/{$sale->json('id')}/receipt")->assertOk();
}

it('prints with the defaults until the owner saves: 80 mm, ask per sale, barcode on, no WhatsApp number no QR', function (): void {
    $response = receiptFor($this)
        ->assertJsonPath('print', PrintSettings::DEFAULTS['receipt'])
        ->assertJsonPath('logo_url', null)
        ->assertJsonPath('whatsapp_link', null)
        ->assertJsonPath('whatsapp_qr_svg', null)
        ->assertJsonPath('customer.phone_masked', '018****2310')
        ->assertJsonPath('lines.0.sku', $this->brick->sku);

    $svg = (string) $response->json('barcode_svg');
    expect($svg)->toStartWith('<svg')->toContain('<rect x="10"');
    expect($response->getContent())->not->toContain(PosKit::PHONE)->not->toContain('1819552310');
});

it('carries the saved settings and the store WhatsApp QR', function (): void {
    Setting::put(CompanyDetails::WHATSAPP_KEY, '+8801711000222', SettingGroup::Store, false, null);
    Setting::put(PrintSettings::PREFIX.'receipt', ['paper' => '58', 'language' => 'bn', 'auto_print' => false, 'show_customer_name' => true, 'footer_bn' => 'আবার আসবেন'], SettingGroup::Store, false, null);

    $response = receiptFor($this)
        ->assertJsonPath('print.paper', '58')
        ->assertJsonPath('print.language', 'bn')
        ->assertJsonPath('print.auto_print', false)
        ->assertJsonPath('print.show_customer_name', true)
        ->assertJsonPath('print.footer_bn', 'আবার আসবেন')
        ->assertJsonPath('print.footer_en', null)
        ->assertJsonPath('whatsapp_link', 'https://wa.me/8801711000222')
        ->assertJsonPath('customer.phone_masked', '018****2310');

    expect((string) $response->json('whatsapp_qr_svg'))->toContain('<svg');
});

it('leaves out the barcode and QR the owner switched off', function (): void {
    Setting::put(CompanyDetails::WHATSAPP_KEY, '+8801711000222', SettingGroup::Store, false, null);
    Setting::put(PrintSettings::PREFIX.'receipt', ['show_barcode' => false, 'show_whatsapp_qr' => false, 'show_logo' => false], SettingGroup::Store, false, null);

    receiptFor($this)
        ->assertJsonPath('barcode_svg', null)
        ->assertJsonPath('whatsapp_link', null)
        ->assertJsonPath('whatsapp_qr_svg', null)
        ->assertJsonPath('logo_url', null);
});

it('draws Code 128 bars for printable ASCII only, and wa.me links from E.164 digits', function (): void {
    $codes = new ReceiptCodes;
    expect($codes->code128Svg(''))->toBeNull()
        ->and($codes->code128Svg('টয়'))->toBeNull()
        ->and($codes->code128Svg('TMB-26-00042'))->toContain('viewBox="0 0 ')
        ->and(ReceiptCodes::whatsappLink('+8801711000222'))->toBe('https://wa.me/8801711000222')
        ->and(ReceiptCodes::whatsappLink(null))->toBeNull()
        ->and(ReceiptCodes::whatsappLink(''))->toBeNull();
});
