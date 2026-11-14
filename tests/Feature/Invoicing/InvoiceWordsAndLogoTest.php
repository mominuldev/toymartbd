<?php

declare(strict_types=1);

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Media\Services\MediaUsages;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Invoicing\Actions\IssuePurchaseInvoice;
use App\Domain\Invoicing\Actions\IssueSalesInvoice;
use App\Domain\Invoicing\Models\CreditNote;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\AmountInWords;
use App\Domain\Invoicing\Services\InvoiceLogo;
use App\Domain\Ordering\Actions\CancelOrder;
use App\Domain\Ordering\Enums\CancellationReason;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Purchasing\Models\Supplier;
use App\Models\Enums\Role;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Ordering\Support\OrderFixtures;
use Tests\Feature\Orders\Support;
use Tests\Feature\Purchasing\PurchasingKit;
use Tests\Feature\Returns\ReturnsKit;
use Tests\Support\InvoiceLogoKit;
use Tests\Support\PdfText;

/*
 * Phase 8 closeout (P9 batch 1): the total in Bangla words — table-driven lakh/crore, on the
 * snapshot of documents issued from now on only — and the company logo on invoices and
 * credit notes, referenced at issue, drawn from a local file only, absent = no image.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    InvoiceLogoKit::fakeDisk();
});

function nfc(string $s): string
{
    return (string) Normalizer::normalize($s, Normalizer::FORM_C);
}

function wlInvoice(string $total = '1250.00'): Invoice
{
    $order = Order::factory()->create(['grand_total' => $total]);
    OrderItem::factory()->create(['order_id' => $order->id]);

    return app(IssueSalesInvoice::class)->handle($order);
}

/** An invoiced, then cancelled order → its invoice and its full credit note. */
function wlCreditNote(): CreditNote
{
    $variant = ReturnsKit::variant(10, '1250.00', '800.00');
    $placed = OrderFixtures::place([$variant->id => 2]);
    app(IssueSalesInvoice::class)->handle($placed->refresh());
    app(CancelOrder::class)->handle($placed, CancellationReason::CustomerRequest);

    return CreditNote::query()->where('order_id', $placed->id)->sole();
}

it('spells amounts in Bangla with lakh and crore', function (string $amount, string $words): void {
    expect(AmountInWords::bangla(Money::of($amount)))->toBe(nfc($words));
})->with([
    'zero' => ['0', 'শূন্য টাকা মাত্র'],
    'one' => ['1', 'এক টাকা মাত্র'],
    'nineteen' => ['19', 'উনিশ টাকা মাত্র'],
    'ninety-nine' => ['99', 'নিরানব্বই টাকা মাত্র'],
    'hundred' => ['100', 'একশত টাকা মাত্র'],
    'hundred and one' => ['101', 'একশত এক টাকা মাত্র'],
    'thousand' => ['1000', 'এক হাজার টাকা মাত্র'],
    '1,25,300' => ['125300', 'এক লক্ষ পঁচিশ হাজার তিনশত টাকা মাত্র'],
    '10,00,000' => ['1000000', 'দশ লক্ষ টাকা মাত্র'],
    '1,00,00,000' => ['10000000', 'এক কোটি টাকা মাত্র'],
    'paisa' => ['2450.50', 'দুই হাজার চারশত পঞ্চাশ টাকা পঞ্চাশ পয়সা মাত্র'],
    'one paisa' => ['0.01', 'শূন্য টাকা এক পয়সা মাত্র'],
    'every group' => ['987654321.99', 'আটানব্বই কোটি ছিয়াত্তর লক্ষ চুয়ান্ন হাজার তিনশত একুশ টাকা নিরানব্বই পয়সা মাত্র'],
    'crore over 99' => ['1234567890.05', 'একশত তেইশ কোটি পঁয়তাল্লিশ লক্ষ সাতষট্টি হাজার আটশত নব্বই টাকা পাঁচ পয়সা মাত্র'],
    'negative is spelt as its size' => ['-501', 'পাঁচশত এক টাকা মাত্র'],
]);

it('keeps the 0–99 table complete and distinct', function (): void {
    $words = array_map(static fn (int $n): string => AmountInWords::banglaNumber($n), range(0, 99));
    expect(array_unique($words))->toHaveCount(100)
        // No digit, Western or Bengali, ever leaks into the words.
        ->and(implode(' ', $words))->not->toMatch('/[0-9০-৯]/u')
        ->and(AmountInWords::banglaNumber(21))->toBe(nfc('একুশ'))
        ->and(AmountInWords::banglaNumber(79))->toBe(nfc('উনআশি'));
});

it('keeps English words unchanged (ICU en_IN, lakh/crore)', function (): void {
    expect(AmountInWords::english(Money::of('125300')))->toBe('Taka one lakh twenty-five thousand three hundred only')
        ->and(AmountInWords::english(Money::of('2450.50')))->toBe('Taka two thousand four hundred fifty and fifty paisa only');
});

it('stores Bangla words on invoices and credit notes issued from now on and prints them on the Bangla PDF', function (): void {
    Support::actingAs(Role::Manager);
    $note = wlCreditNote();
    $invoice = Invoice::query()->findOrFail($note->invoice_id);

    expect($invoice->amount_in_words_bn)->toBe(AmountInWords::bangla($invoice->total))
        ->and($note->amount_in_words_bn)->toBe(AmountInWords::bangla($note->total))
        ->and($note->amount_in_words_bn)->toStartWith(nfc('দুই হাজার পাঁচশত'));
    $this->getJson("/api/v1/admin/invoices/{$invoice->id}")->assertOk()->assertJsonPath('amount_in_words_bn', $invoice->amount_in_words_bn);
    $this->getJson("/api/v1/admin/credit-notes/{$note->id}")->assertOk()->assertJsonPath('amount_in_words_bn', $note->amount_in_words_bn);

    foreach (["/api/v1/admin/invoices/{$invoice->id}/pdf", "/api/v1/admin/credit-notes/{$note->id}/pdf"] as $url) {
        $bn = PdfText::banglaLetters(PdfText::extract((string) $this->get($url.'?locale=bn')->assertOk()->getContent()));
        $en = PdfText::extract((string) $this->get($url.'?locale=en')->assertOk()->getContent());
        // Conjuncts print as private-use glyphs, so only conjunct-free words are matched.
        expect($bn)->toContain(PdfText::banglaLetters('কথায়'))->toContain(PdfText::banglaLetters('দুই হাজার পাঁচশত'))->toContain(PdfText::banglaLetters('টাকা'))
            ->and($en)->toContain('In words: Taka two thousand five hundred seventy only');
    }
});

it('leaves an old document without Bangla words exactly as before', function (): void {
    Support::actingAs(Role::Manager);
    $invoice = wlInvoice();
    // A snapshot issued before P9: no Bangla words (raw write — the model refuses updates).
    DB::table('invoices')->where('id', $invoice->id)->update(['amount_in_words_bn' => null]);

    $bn = PdfText::banglaLetters(PdfText::extract((string) $this->get("/api/v1/admin/invoices/{$invoice->id}/pdf?locale=bn")->assertOk()->getContent()));
    $en = PdfText::extract((string) $this->get("/api/v1/admin/invoices/{$invoice->id}/pdf?locale=en")->assertOk()->getContent());
    expect($bn)->not->toContain(PdfText::banglaLetters('কথায়'))
        ->and($en)->toContain('In words: Taka')
        ->and(Invoice::query()->findOrFail($invoice->id)->amount_in_words_bn)->toBeNull();
});

it('gives a supplier invoice Bangla words too', function (): void {
    Support::actingAs(Role::InventoryStaff);
    $po = PurchasingKit::purchase([[ProductVariant::factory()->create(), 3, '100.0000']], Supplier::factory()->create());
    $invoice = app(IssuePurchaseInvoice::class)->handle($po);

    expect($invoice->amount_in_words_bn)->toBe(AmountInWords::bangla($invoice->total));
});

it('draws the chosen logo on invoice and credit note PDFs in English and Bangla', function (): void {
    Support::actingAs(Role::Manager);
    $logo = InvoiceLogoKit::media();
    InvoiceLogoKit::choose($logo);
    $note = wlCreditNote();
    $invoice = Invoice::query()->findOrFail($note->invoice_id);

    expect($invoice->issued_by['logo'])->toBe(['media_id' => $logo->id, 'disk' => 'public', 'path' => $logo->base_path.'/480.webp'])
        ->and($note->issued_by['logo']['media_id'])->toBe($logo->id);

    foreach (["/api/v1/admin/invoices/{$invoice->id}/pdf", "/api/v1/admin/credit-notes/{$note->id}/pdf"] as $url) {
        foreach (['en', 'bn'] as $locale) {
            expect(InvoiceLogoKit::imageCount((string) $this->get("{$url}?locale={$locale}")->assertOk()->getContent()))->toBeGreaterThan(0);
        }
    }
});

it('prints no image, and no error, without a logo or when the file is gone', function (): void {
    Support::actingAs(Role::Manager);
    $plain = wlInvoice();
    expect($plain->issued_by['logo'])->toBeNull();

    InvoiceLogoKit::choose(InvoiceLogoKit::media(withFile: false));
    $missing = wlInvoice();
    expect($missing->issued_by['logo'])->not->toBeNull();

    foreach ([$plain, $missing] as $invoice) {
        foreach (['en', 'bn'] as $locale) {
            expect(InvoiceLogoKit::imageCount((string) $this->get("/api/v1/admin/invoices/{$invoice->id}/pdf?locale={$locale}")->assertOk()->getContent()))->toBe(0);
        }
    }
});

it('reprints with the logo it was issued with after the logo is changed', function (): void {
    Support::actingAs(Role::Manager);
    $first = InvoiceLogoKit::media();
    InvoiceLogoKit::choose($first);
    $invoice = wlInvoice();
    $second = InvoiceLogoKit::media();
    InvoiceLogoKit::choose($second);
    $later = wlInvoice();

    expect(Invoice::query()->findOrFail($invoice->id)->issued_by['logo']['media_id'])->toBe($first->id)
        ->and($later->issued_by['logo']['media_id'])->toBe($second->id)
        ->and(InvoiceLogo::localPath(Invoice::query()->findOrFail($invoice->id)->issued_by))->toBe(Storage::disk('public')->path($first->base_path.'/480.webp'));
});

it('never hands mPDF a URL, a path outside the disk, or a file on a remote disk', function (array $logo): void {
    expect(InvoiceLogo::localPath(['logo' => $logo]))->toBeNull();
})->with([
    'url' => [['media_id' => 1, 'disk' => 'public', 'path' => 'https://example.com/logo.webp']],
    'traversal' => [['media_id' => 1, 'disk' => 'public', 'path' => '../../.env']],
    'absolute' => [['media_id' => 1, 'disk' => 'public', 'path' => '/etc/passwd']],
    'unknown disk' => [['media_id' => 1, 'disk' => 'nope', 'path' => 'media/x/480.webp']],
    'malformed' => [['media_id' => 1]],
]);

it('refuses a document or an unready image as the logo, accepts a ready image, and shows its preview', function (): void {
    Support::actingAs(Role::Manager);
    $doc = Media::factory()->document()->create();
    $processing = Media::factory()->create(['processing_status' => 'processing']);
    $image = InvoiceLogoKit::media();

    foreach ([$doc, $processing] as $bad) {
        $this->putJson('/api/v1/admin/settings/invoice', ['logo_media_id' => $bad->id])->assertUnprocessable()->assertJsonPath('errors.0.field', 'logo_media_id');
    }
    $this->putJson('/api/v1/admin/settings/invoice', ['logo_media_id' => $image->id])->assertOk()
        ->assertJsonPath('logo_media_id', $image->id)
        ->assertJsonPath('logo_url', $image->url(320));
    $this->getJson('/api/v1/admin/settings/invoice')->assertOk()->assertJsonPath('logo_url', $image->url(320));
});

it('keeps the chosen invoice logo from being deleted from the media library', function (): void {
    $logo = InvoiceLogoKit::media(withFile: false);
    $other = InvoiceLogoKit::media(withFile: false);
    InvoiceLogoKit::choose($logo);
    $usages = app(MediaUsages::class);

    expect($usages->isUsed($logo->id))->toBeTrue()
        ->and($usages->for($logo->id)[0]->type)->toBe('invoice_logo')
        ->and($usages->isUsed($other->id))->toBeFalse()
        ->and($usages->whereUsed(Media::query(), true)->pluck('id')->all())->toBe([$logo->id])
        ->and($usages->whereUsed(Media::query(), false)->pluck('id')->all())->toBe([$other->id]);

    InvoiceLogoKit::choose(null);
    expect(app(MediaUsages::class)->isUsed($logo->id))->toBeFalse();
});
