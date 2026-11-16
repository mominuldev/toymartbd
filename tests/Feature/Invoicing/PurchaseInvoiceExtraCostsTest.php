<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Invoicing\Actions\IssuePurchaseInvoice;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\Supplier;
use App\Models\Enums\Role;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Orders\Support;
use Tests\Feature\Purchasing\PurchasingKit as Kit;
use Tests\Support\PdfText;

/*
 * The supplier (purchase) invoice matches the PO page (owner decision 2026-11-16): the goods
 * ordered, then the extra costs THE SUPPLIER CHARGED as their own lines, total = goods + those
 * extras; extras paid to someone else never appear. Old-rule POs (extras added to the PO header)
 * issue the same figures; an invoice already issued keeps its snapshot on every reprint.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    Support::actingAs(Role::Manager);
    $this->supplier = Supplier::factory()->create(['name' => 'বংশাল কিডস · Bangshal Kids Imports', 'payment_terms_days' => 15]);
    $this->variant = ProductVariant::factory()->create(['sku' => 'TMB-WAP-BN']);
});

/** What a receipt did to the PO header before the decision: its extras added there, and to `total`. */
function oldRuleHeader(Purchase $po, string $freight = '0', string $customs = '0'): void
{
    $po->refresh();
    $po->shipping_cost = $po->shipping_cost->plus(Money::of($freight));
    $po->customs_duty = $po->customs_duty->plus(Money::of($customs));
    $po->total = $po->total->plus(Money::of($freight))->plus(Money::of($customs));
    $po->save();
}

function pdfOf(Invoice $invoice, string $locale): string
{
    $text = PdfText::extract((string) test()->get("/api/v1/admin/invoices/{$invoice->id}/pdf?locale={$locale}")->assertOk()->getContent());

    return (string) preg_replace('/\s+/u', ' ', $text);
}

it('lists only the supplier-charged extras and totals goods + those extras, in English and Bangla', function (): void {
    $po = Kit::purchase([[$this->variant, 10, '500']], $this->supplier);
    Kit::receive($po, [$po->items[0]->id => 4], freight: '100');
    // A C&F agent's customs, paid by the shop: never on the supplier's invoice.
    Kit::receive($po->refresh(), [$po->items[0]->id => 2], customs: '50', costsToSupplier: false);

    $invoice = app(IssuePurchaseInvoice::class)->handle($po);

    expect($invoice->issued_to['costs'])->toBe(['shipping_cost' => '100.00', 'customs_duty' => '0.00', 'other_cost' => '0.00'])
        ->and($invoice->subtotal->toDecimal())->toBe('5000.00')
        ->and($invoice->shipping->toDecimal())->toBe('100.00')
        ->and($invoice->total->toDecimal())->toBe('5100.00');

    $en = pdfOf($invoice, 'en');
    expect($en)->toContain('Freight')->toContain('PO total ৳5,100')->not->toContain('Customs');
    $bn = PdfText::banglaLetters(pdfOf($invoice, 'bn'));
    expect($bn)->toContain(PdfText::banglaLetters('পরিবহন'))->not->toContain(PdfText::banglaLetters('কাস্টমস'))
        ->and(pdfOf($invoice, 'bn'))->toContain('৳5,100');
});

it('issues the same figures for a PO received under the old rule as under the new one', function (): void {
    $old = Kit::purchase([[$this->variant, 10, '500']], $this->supplier);
    Kit::receive($old, [$old->items[0]->id => 10], freight: '200', customs: '80');
    oldRuleHeader($old, '200', '80');
    // An old-rule PO whose third-party customs went into its header total too.
    $oldThirdParty = Kit::purchase([[$this->variant, 10, '500']], $this->supplier);
    Kit::receive($oldThirdParty, [$oldThirdParty->items[0]->id => 10], customs: '50', costsToSupplier: false);
    oldRuleHeader($oldThirdParty, customs: '50');
    $new = Kit::purchase([[$this->variant, 10, '500']], $this->supplier);
    Kit::receive($new, [$new->items[0]->id => 10], freight: '200', customs: '80');

    expect($old->refresh()->total->toDecimal())->toBe('5280.00')
        ->and($oldThirdParty->refresh()->total->toDecimal())->toBe('5050.00')
        ->and($new->refresh()->total->toDecimal())->toBe('5000.00');

    $issue = app(IssuePurchaseInvoice::class);
    foreach ([$old, $new] as $po) {
        $invoice = $issue->handle($po);
        expect($invoice->total->toDecimal())->toBe('5280.00')
            ->and($invoice->issued_to['costs'])->toBe(['shipping_cost' => '200.00', 'customs_duty' => '80.00', 'other_cost' => '0.00'])
            ->and(pdfOf($invoice, 'en'))->toContain('Freight')->toContain('Customs & duty')->toContain('PO total ৳5,280');
    }
    $third = $issue->handle($oldThirdParty);
    expect($third->total->toDecimal())->toBe('5000.00')
        ->and($third->shipping->toDecimal())->toBe('0.00')
        ->and(pdfOf($third, 'en'))->not->toContain('Customs');
});

it('reprints an invoice issued under the old rule from its snapshot, figures unchanged', function (): void {
    $po = Kit::purchase([[$this->variant, 10, '500']], $this->supplier);
    Kit::receive($po, [$po->items[0]->id => 10], customs: '50', costsToSupplier: false);
    $invoice = app(IssuePurchaseInvoice::class)->handle($po);
    // As the old action wrote it: the third-party customs in the snapshot and its total (raw — the model refuses updates).
    $issuedTo = $invoice->issued_to;
    $issuedTo['costs'] = ['shipping_cost' => '0.00', 'customs_duty' => '50.00', 'other_cost' => '0.00'];
    DB::table('invoices')->where('id', $invoice->id)->update(['issued_to' => json_encode($issuedTo), 'shipping' => '50.00', 'total' => '5050.00']);
    $before = DB::table('invoices')->where('id', $invoice->id)->first();

    $first = pdfOf($invoice, 'en');
    // Later activity on the PO never reaches the issued invoice.
    app(IssuePurchaseInvoice::class)->handle($po->refresh());
    $second = pdfOf($invoice, 'en');
    $firstBn = pdfOf($invoice, 'bn');
    $secondBn = pdfOf($invoice, 'bn');

    $figures = static fn (string $text): array => (preg_match_all('/৳[\d,]+(?:\.\d+)?/u', $text, $m) ? $m[0] : []);
    expect($figures($second))->toBe($figures($first))
        ->and($figures($secondBn))->toBe($figures($firstBn))
        ->and($first)->toContain('PO total ৳5,050')->toContain('Customs & duty')
        ->and(DB::table('invoices')->where('id', $invoice->id)->first())->toEqual($before)
        ->and(Invoice::query()->where('purchase_id', $po->id)->count())->toBe(1);
});
