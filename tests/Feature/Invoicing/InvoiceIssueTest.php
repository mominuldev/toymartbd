<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\IssueSalesInvoice;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Exceptions\InvoiceImmutable;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Orders\Support;
use Tests\Support\PdfText;

/*
 * P8 invoicing (PRD N-13): sequential gap-free numbering per series, issued once per order
 * on first print, immutable company + line snapshot that a reprint re-renders, Bangla PDF.
 */

function invoicedOrder(array $order = [], array $item = []): Order
{
    $o = Order::factory()->create(['grand_total' => '2450.00', 'subtotal' => '2380.00', 'shipping_charge' => '70.00', 'due_total' => '2450.00', ...$order]);
    OrderItem::factory()->create(['order_id' => $o->id, 'quantity' => 2, 'unit_price' => '1190.00', 'line_total' => '2380.00', 'sku' => 'TMB-RC-01', ...$item]);

    return $o->refresh();
}

it('issues a gap-free INV number on first print and reprints the same invoice', function (): void {
    Support::actingAs(Role::OrderOperator);
    $a = invoicedOrder();
    $b = invoicedOrder();
    $year = now()->setTimezone('Asia/Dhaka')->format('y');

    $first = $this->get("/api/v1/admin/orders/{$a->id}/invoice")->assertOk();
    $this->get("/api/v1/admin/orders/{$b->id}/invoice")->assertOk();
    $again = $this->get("/api/v1/admin/orders/{$a->id}/invoice")->assertOk();

    expect($first->headers->get('X-Invoice-Numbers'))->toBe("{$a->id}:INV-{$year}-000001")
        ->and($again->headers->get('X-Invoice-Numbers'))->toBe("{$a->id}:INV-{$year}-000001")
        ->and(Invoice::query()->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2])
        ->and(Invoice::query()->where('order_id', $a->id)->sole()->total->toDecimal())->toBe('2450.00')
        ->and(PdfText::extract((string) $again->getContent()))->toContain("INV-{$year}-000001")->toContain($a->number)->toContain('TMB-RC-01');
});

it('returns a number to the series when the issuing transaction rolls back (no gap)', function (): void {
    $a = invoicedOrder();
    $b = invoicedOrder();

    try {
        DB::transaction(function () use ($a): void {
            app(IssueSalesInvoice::class)->handle($a);
            throw new RuntimeException('printer jammed');
        });
    } catch (RuntimeException) {
    }

    $invoice = app(IssueSalesInvoice::class)->handle($b);

    expect($invoice->sequence)->toBe(1)
        ->and(Invoice::query()->count())->toBe(1)
        ->and(DB::table('invoice_number_sequences')->where('series', 'INV')->value('last_value'))->toBe(1);
});

it('keeps the snapshot when store settings and the order change afterwards', function (): void {
    Support::actingAs(Role::Manager);
    $this->putJson('/api/v1/admin/settings/invoice', [
        'name_en' => 'Toy Mart BD Ltd', 'address_en' => 'House 12, Road 5, Dhanmondi, Dhaka', 'hotline' => '09600 111222',
        'trade_licence' => 'TRAD/DSCC/111111', 'bin' => '000111222-0101',
    ])->assertOk()->assertJsonPath('placeholders', ['name_bn', 'address_bn']);
    $order = invoicedOrder(['shipping_name' => 'Rahela Khatun']);
    $this->get("/api/v1/admin/orders/{$order->id}/invoice?locale=en")->assertOk();

    $this->putJson('/api/v1/admin/settings/invoice', [
        'name_en' => 'Toy Mart BD Ltd', 'address_en' => 'Plot 9, Gulshan 2, Dhaka', 'hotline' => '09600 999888',
        'trade_licence' => 'TRAD/DNCC/999999', 'bin' => '999888777-0101',
    ])->assertOk();
    DB::table('orders')->where('id', $order->id)->update(['shipping_name' => 'Someone Else', 'grand_total' => '9999.00']);

    $invoice = Invoice::query()->where('order_id', $order->id)->sole();
    $text = PdfText::extract((string) $this->get("/api/v1/admin/invoices/{$invoice->id}/pdf?locale=en")->assertOk()->getContent());

    expect($invoice->issued_by['bin'])->toBe('000111222-0101')
        ->and($text)->toContain('000111222-0101')->toContain('Dhanmondi')->toContain('Rahela Khatun')->toContain('2,450')
        ->and($text)->not->toContain('999888777')->not->toContain('Gulshan')->not->toContain('Someone Else')->not->toContain('9,999');

    // A new invoice takes the new details.
    $next = invoicedOrder();
    expect(app(IssueSalesInvoice::class)->handle($next)->issued_by['bin'])->toBe('999888777-0101');
});

it('prints [placeholder] for company details the owner has not set', function (): void {
    Support::actingAs(Role::Manager);
    $order = invoicedOrder();

    $text = PdfText::extract((string) $this->get("/api/v1/admin/orders/{$order->id}/invoice?locale=en")->assertOk()->getContent());
    $invoice = Invoice::query()->sole();

    expect($text)->toContain('[placeholder]')->toContain('Toy Mart BD')
        ->and($invoice->issued_by['placeholders'])->toContain('bin', 'trade_licence', 'hotline');
});

it('never edits or deletes an issued invoice', function (): void {
    $invoice = app(IssueSalesInvoice::class)->handle(invoicedOrder());

    expect(fn () => $invoice->update(['total' => '1.00']))->toThrow(InvoiceImmutable::class)
        ->and(fn () => $invoice->delete())->toThrow(InvoiceImmutable::class);
});

it('renders a Bangla invoice whose customer and product names shape and extract', function (): void {
    Support::actingAs(Role::Manager);
    $order = invoicedOrder(
        ['shipping_name' => 'রাহেলা খাতুন', 'shipping_landmark' => 'জামে মসজিদের পাশে'],
        ['product_name' => 'Red car', 'product_name_bn' => 'লাল গাড়ি'],
    );
    OrderItem::factory()->create(['order_id' => $order->id, 'quantity' => 1, 'product_name_bn' => 'রিমোট কন্ট্রোল গাড়ি', 'sku' => 'TMB-RC-02']);

    $pdf = (string) $this->get("/api/v1/admin/orders/{$order->id}/invoice?locale=bn")->assertOk()->getContent();
    $text = PdfText::extract($pdf);

    expect($pdf)->toContain('HindSiliguri')
        // Latin and digits extract exactly (rule 36: Western numerals in Bangla too).
        ->and($text)->toContain(Invoice::query()->sole()->number)->toContain('TMB-RC-02')
        // Bangla: every letter of the names comes back as a Bengali code point, in order
        // (glyph order is visual, so vowel signs are compared out; see PdfText).
        ->and(PdfText::banglaLetters($text))->toContain(PdfText::banglaLetters('রাহেলা খাতুন'))
        ->toContain(PdfText::banglaLetters('লাল গাড়ি'))
        ->toContain(PdfText::banglaLetters('ইনভয়েস'))
        // The conjunct ন্ট্র is one shaped glyph (private use), not three broken letters.
        ->and(preg_match('/[\x{E000}-\x{F8FF}]/u', $text))->toBe(1);
});

it('refuses to invoice a cancelled order and reports bulk results per order', function (): void {
    Support::actingAs(Role::OrderOperator);
    $ok = invoicedOrder();
    $cancelled = invoicedOrder(['status' => OrderStatus::Cancelled]);

    $this->getJson("/api/v1/admin/orders/{$cancelled->id}/invoice")->assertStatus(409);

    $response = $this->postJson('/api/v1/admin/orders/bulk/invoices', ['order_ids' => [$cancelled->id, 999999, $ok->id]])->assertOk();
    $number = Invoice::query()->where('order_id', $ok->id)->sole()->number;

    expect($response->headers->get('X-Invoice-Numbers'))->toBe("{$ok->id}:{$number}")
        ->and($response->headers->get('X-Orders-Not-Found'))->toBe('999999')
        ->and($response->headers->get('X-Orders-Not-Invoiced'))->toBe("{$cancelled->id}:cancelled")
        ->and(Invoice::query()->where('order_id', $cancelled->id)->exists())->toBeFalse();

    $this->postJson('/api/v1/admin/orders/bulk/invoices', ['order_ids' => [$cancelled->id]])->assertStatus(409);
});

it('keeps an invoice issued before the order was cancelled', function (): void {
    Support::actingAs(Role::OrderOperator);
    $order = invoicedOrder();
    $this->get("/api/v1/admin/orders/{$order->id}/invoice")->assertOk();
    DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::Cancelled->value]);

    $this->get("/api/v1/admin/orders/{$order->id}/invoice")->assertOk();
    expect(Invoice::query()->where('type', InvoiceType::Sales->value)->count())->toBe(1);
});
