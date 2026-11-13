<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\IssueSalesInvoice;
use App\Domain\Invoicing\Models\CreditNote;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Ordering\Actions\CancelOrder;
use App\Domain\Ordering\Enums\CancellationReason;
use App\Domain\Ordering\Models\OrderItem;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Ordering\Support\OrderFixtures;
use Tests\Feature\Orders\Support;
use Tests\Feature\Returns\ReturnsKit;
use Tests\Support\PdfText;
use Tests\Support\RoleMatrix;

/*
 * P8 batch 2 credit-note API: list (allowlisted filters and sorts), one credit note (PII
 * masked), the PDF in English and Bangla, the invoice's credit notes and balance, and the
 * 403 matrix for every endpoint (rule 21).
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

/** An invoiced, then cancelled order → one full credit note. */
function cnCancelledCreditNote(array $order = [], array $item = []): CreditNote
{
    $variant = ReturnsKit::variant(10, '1250.00', '800.00');
    $placed = OrderFixtures::place([$variant->id => 2], overrides: $order);
    if ($item !== []) {
        OrderItem::query()->where('order_id', $placed->id)->update($item);
    }
    app(IssueSalesInvoice::class)->handle($placed->refresh());
    app(CancelOrder::class)->handle($placed, CancellationReason::CustomerRequest);

    return CreditNote::query()->where('order_id', $placed->id)->sole();
}

it('lists credit notes newest first with allowlisted filters and sorts', function (): void {
    $a = cnCancelledCreditNote(['shipping_name' => 'Nusrat Jahan']);
    $b = cnCancelledCreditNote(['shipping_name' => 'রাহেলা খাতুন']);
    Support::actingAs(Role::Manager);

    $all = $this->getJson('/api/v1/admin/credit-notes')->assertOk()->json('data');
    expect(array_column($all, 'number'))->toBe([$b->number, $a->number])
        ->and($all[0])->toMatchArray([
            'invoice_id' => $b->invoice_id, 'invoice_number' => $b->invoice_number, 'order_id' => $b->order_id,
            'reason' => 'order_cancelled', 'issued_to_name' => 'রাহেলা খাতুন', 'total' => '2570.00', 'can_print' => true,
        ])
        ->and($all[0])->not->toHaveKey('issued_to');

    $ids = fn (string $query): array => array_column($this->getJson('/api/v1/admin/credit-notes?'.$query)->assertOk()->json('data'), 'id');
    $today = now()->setTimezone('Asia/Dhaka')->toDateString();
    expect($ids('filter[invoice]='.$a->invoice_id))->toBe([$a->id])
        ->and($ids('filter[order]='.$b->order_id))->toBe([$b->id])
        ->and($ids('filter[search]='.urlencode($a->number)))->toBe([$a->id])
        ->and($ids('filter[search]='.urlencode($b->invoice_number)))->toBe([$b->id])
        ->and($ids('filter[search]='.urlencode('রাহেলা')))->toBe([$b->id])
        ->and($ids('filter[reason]=refund'))->toBe([])
        ->and($ids("filter[issued_from]={$today}&filter[issued_to]={$today}"))->toHaveCount(2)
        ->and($ids('sort=number'))->toBe([$a->id, $b->id])
        ->and($ids('sort=-total'))->toHaveCount(2);

    $this->getJson('/api/v1/admin/credit-notes?filter[status]=open')->assertStatus(400);
    $this->getJson('/api/v1/admin/credit-notes?filter[reason]=goodwill')->assertStatus(400);
    $this->getJson('/api/v1/admin/credit-notes?filter[invoice]=abc')->assertStatus(400);
    $this->getJson('/api/v1/admin/credit-notes?filter[issued_from]=13-11-2026')->assertStatus(400);
    $this->getJson('/api/v1/admin/credit-notes?sort=reason')->assertStatus(400)->assertJsonPath('type', fn (string $type): bool => str_contains($type, 'bad-request'));
});

it('shows one credit note with PII masked without customers.view_pii', function (): void {
    $note = cnCancelledCreditNote(['shipping_phone' => '+8801712345678', 'shipping_address' => 'House 7, Road 3']);

    Support::actingAs(Role::Viewer);
    $masked = $this->getJson("/api/v1/admin/credit-notes/{$note->id}")->assertOk()->json();
    expect($masked['issued_to']['phone'])->not->toContain('12345678')
        ->and($masked['issued_to'])->not->toHaveKey('address')
        ->and($masked['can_print'])->toBeFalse()
        ->and($masked['lines'][0])->toMatchArray(['kind' => 'item', 'quantity' => 2, 'amount' => '2500.00'])
        ->and($masked)->toMatchArray(['subtotal' => '2500.00', 'shipping' => '70.00', 'adjustment' => '0.00', 'total' => '2570.00']);

    auth()->forgetGuards();
    Support::actingAs(Role::OrderOperator);
    expect($this->getJson("/api/v1/admin/credit-notes/{$note->id}")->assertOk()->json('issued_to.address'))->toBe('House 7, Road 3');
});

it('shows the credit notes and the remaining balance on the invoice', function (): void {
    $note = cnCancelledCreditNote();
    $plain = app(IssueSalesInvoice::class)->handle(OrderFixtures::place([ReturnsKit::variant()->id => 1]));
    Support::actingAs(Role::Manager);

    $invoice = $this->getJson("/api/v1/admin/invoices/{$note->invoice_id}")->assertOk()->json();
    expect($invoice)->toMatchArray(['total' => '2570.00', 'credited' => '2570.00', 'balance' => '0.00'])
        ->and(array_column($invoice['credit_notes'], 'number'))->toBe([$note->number]);

    $rows = collect($this->getJson('/api/v1/admin/invoices')->assertOk()->json('data'))->keyBy('id');
    expect($rows[$note->invoice_id]['credit_notes'][0]['total'])->toBe('2570.00')
        ->and($rows[$plain->id])->toMatchArray(['credited' => '0.00', 'balance' => $plain->total->toDecimal(), 'credit_notes' => []]);
});

it('renders the credit note PDF in English and Bangla from the snapshot', function (): void {
    $note = cnCancelledCreditNote(['shipping_name' => 'রাহেলা খাতুন'], ['product_name' => 'Red car', 'product_name_bn' => 'লাল গাড়ি']);
    Support::actingAs(Role::Manager);

    $en = $this->get("/api/v1/admin/credit-notes/{$note->id}/pdf?locale=en")->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Invoice-Numbers', $note->number);
    $text = PdfText::extract((string) $en->getContent());
    expect($text)->toContain('Credit Note')->toContain($note->number)->toContain($note->invoice_number)
        ->toContain('Red car')->toContain('2,570')->toContain('Order cancelled after the invoice was issued');

    $bn = (string) $this->get("/api/v1/admin/credit-notes/{$note->id}/pdf?locale=bn")->assertOk()->getContent();
    $bnText = PdfText::extract($bn);
    expect($bn)->toContain('HindSiliguri')
        ->and($bnText)->toContain($note->number)->toContain($note->invoice_number)->toContain('2,570')
        // "ক্রে" is one shaped conjunct glyph (private use), so the title is matched after it.
        ->and(PdfText::banglaLetters($bnText))->toContain(PdfText::banglaLetters('ডিট নোট'))
        ->toContain(PdfText::banglaLetters('মূল ইনভয়েস'))
        ->toContain(PdfText::banglaLetters('লাল গাড়ি'))
        ->toContain(PdfText::banglaLetters('রাহেলা খাতুন'));

    // The customer's preferred language is the default.
    $this->get("/api/v1/admin/credit-notes/{$note->id}/pdf")->assertOk();
    $this->getJson("/api/v1/admin/credit-notes/{$note->id}/pdf?locale=fr")->assertUnprocessable();
});

describe('role matrix', function (): void {
    it('guards the credit-note list and detail with orders.view', function (Role $role): void {
        $note = cnCancelledCreditNote();
        $allowed = RoleMatrix::allows($role, Permission::OrdersView);
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/credit-notes'));
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson("/api/v1/admin/credit-notes/{$note->id}"));
    })->with(array_map(static fn (Role $r): array => [$r], Role::cases()));

    it('guards the credit-note PDF with orders.view + customers.view_pii', function (Role $role): void {
        $note = cnCancelledCreditNote();
        $allowed = RoleMatrix::allows($role, Permission::OrdersView) && RoleMatrix::allows($role, Permission::CustomersViewPii);
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->get("/api/v1/admin/credit-notes/{$note->id}/pdf", ['Accept' => 'application/json']));
    })->with(array_map(static fn (Role $r): array => [$r], Role::cases()));

    it('refuses a guest with 401', function (): void {
        $this->getJson('/api/v1/admin/credit-notes')->assertUnauthorized();
        $this->getJson('/api/v1/admin/credit-notes/1')->assertUnauthorized();
        $this->getJson('/api/v1/admin/credit-notes/1/pdf')->assertUnauthorized();
    });
});
