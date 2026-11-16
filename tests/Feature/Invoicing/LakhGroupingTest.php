<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Invoicing\Actions\IssueSalesInvoice;
use App\Domain\Invoicing\Models\CreditNote;
use App\Domain\Ordering\Actions\CancelOrder;
use App\Domain\Ordering\Enums\CancellationReason;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Purchasing\Models\Supplier;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Ordering\Support\OrderFixtures;
use Tests\Feature\Orders\Support;
use Tests\Feature\Purchasing\PurchasingKit;
use Tests\Feature\Returns\ReturnsKit;
use Tests\Support\PdfText;

/*
 * Owner decision 2026-11-16: taka on the admin's documents uses South Asian (lakh) grouping —
 * ৳12,34,567.89, never ৳1,234,567.89 — with Western digits in English AND Bangla (rule 36).
 * The figures are the snapshot's; only their grouping is presentation.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

/** The PDF's extracted text with whitespace collapsed. */
function lakhPdfText(string $pdf): string
{
    return (string) preg_replace('/\s+/u', ' ', PdfText::extract($pdf));
}

it('groups a sales invoice by lakh in English and Bangla', function (string $locale): void {
    Support::actingAs(Role::OrderOperator);
    $order = Order::factory()->create(['grand_total' => '1234567.89', 'subtotal' => '1234497.89', 'shipping_charge' => '70.00', 'due_total' => '1234567.89']);
    OrderItem::factory()->create(['order_id' => $order->id, 'quantity' => 1, 'unit_price' => '1234497.89', 'line_total' => '1234497.89', 'sku' => 'TMB-LAKH-01']);

    $text = lakhPdfText((string) $this->get("/api/v1/admin/orders/{$order->id}/invoice?locale={$locale}")->assertOk()->getContent());

    expect($text)->toContain('৳12,34,567.89')->toContain('৳12,34,497.89')->toContain('৳70')
        ->not->toContain('1,234,567')->not->toMatch('/[০-৯]/u');
})->with(['en', 'bn']);

it('groups a credit note by lakh in English and Bangla', function (string $locale): void {
    $variant = ReturnsKit::variant(10, '62150.00', '800.00');
    $placed = OrderFixtures::place([$variant->id => 2]);
    app(IssueSalesInvoice::class)->handle($placed->refresh());
    app(CancelOrder::class)->handle($placed, CancellationReason::CustomerRequest);
    $note = CreditNote::query()->where('order_id', $placed->id)->sole();
    Support::actingAs(Role::Manager);

    $text = lakhPdfText((string) $this->get("/api/v1/admin/credit-notes/{$note->id}/pdf?locale={$locale}")->assertOk()->getContent());

    expect($text)->toContain('৳62,150')->toContain('৳1,24,300')->not->toContain('124,300')->not->toMatch('/[০-৯]/u');
})->with(['en', 'bn']);

it('groups a purchase invoice by lakh, 4-place unit costs included, in English and Bangla', function (string $locale): void {
    Support::actingAs(Role::InventoryStaff);
    $costly = ProductVariant::factory()->create(['sku' => 'TMB-LAKH-PO']);
    $bulk = ProductVariant::factory()->create(['sku' => 'TMB-LAKH-BULK']);
    $supplier = Supplier::factory()->create(['name' => 'Dhaka Plush House', 'payment_terms_days' => 15]);
    $po = PurchasingKit::purchase([[$costly, 1000, '1234.5678'], [$bulk, 120000, '10']], $supplier);

    $text = lakhPdfText((string) $this->get("/api/v1/admin/purchases/{$po->id}/invoice?locale={$locale}")->assertOk()->getContent());

    expect($text)->toContain('৳1,234.5678')->toContain('৳12,34,567.80')->toContain('1,20,000')->toContain('৳12,00,000')
        ->toContain('৳24,34,567.80')->not->toContain('2,434,567')->not->toMatch('/[০-৯]/u');
})->with(['en', 'bn']);
