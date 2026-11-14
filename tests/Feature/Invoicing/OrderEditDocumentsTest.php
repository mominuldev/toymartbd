<?php

declare(strict_types=1);

use App\Domain\Inventory\DTOs\StockMovementLine;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Inventory\Services\StockReservations;
use App\Domain\Invoicing\Actions\IssueCourierReturnCreditNote;
use App\Domain\Invoicing\Actions\IssueOrderEditDocuments;
use App\Domain\Invoicing\Actions\IssueSalesInvoice;
use App\Domain\Invoicing\Enums\CreditNoteReason;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Listeners\IssueCreditNoteOnOrderReturned;
use App\Domain\Invoicing\Listeners\IssueDocumentsOnOrderEdited;
use App\Domain\Invoicing\Models\CreditNote;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Ordering\Actions\CancelOrder;
use App\Domain\Ordering\Actions\PlaceOrder;
use App\Domain\Ordering\Actions\RecordCourierReturn;
use App\Domain\Ordering\Enums\CancellationReason;
use App\Domain\Ordering\Enums\FulfillmentStatus;
use App\Domain\Ordering\Enums\OrderEventType;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Events\OrderEdited;
use App\Domain\Ordering\Events\OrderReturned;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderEvent;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\Models\OrderReturn;
use App\Domain\Ordering\Services\OrderTransitions;
use App\Domain\Payment\Actions\CollectCodPayment;
use App\Domain\Payment\Gateways\NullGateway;
use App\Domain\Payment\Models\Payment;
use App\Models\Enums\Role;
use App\Support\Money;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Feature\Orders\Support;
use Tests\Support\PdfText;

/*
 * P8 batch 3 (owner decisions 2026-11-13): an order edited after its invoice was issued gets
 * a credit note for the reduction and a supplementary invoice for the increase — per line,
 * one edit can produce both; a courier return (RTO) of an invoiced order gets a credit note
 * for the whole remaining balance. Replay-safe (UNIQUE source), gap-free numbering, the
 * invoice balance covers supplementary invoices, and none of it ever writes the ledger.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    config([
        'risk.first_order_hold_threshold' => '100000.00',
        'services.storefront.url' => '', 'services.storefront.revalidate_secret' => '',
        'returns.approval_threshold' => '100000.00',
    ]);
    NullGateway::reset();
    Support::actingAs(Role::Manager);
    $this->year = now()->setTimezone('Asia/Dhaka')->format('y');
});

/** @param  array<int, int>  $lines */
function oedPlace(array $lines): Order
{
    return app(PlaceOrder::class)->handle(CheckoutKit::data(CheckoutKit::cart($lines)))->refresh();
}

/**
 * The real edit, through the API (quote first, as the screen does).
 *
 * @param  array<int, int>  $lines
 * @param  array<string, mixed>  $overrides
 */
function oedEdit(Order $order, array $lines, array $overrides = []): Order
{
    $body = Support::body($lines, $overrides);
    unset($body['source']);
    $body['expected_total'] = test()->postJson("/api/v1/admin/orders/{$order->id}/edit-quote", $body)->assertOk()->json('grand_total');
    test()->putJson("/api/v1/admin/orders/{$order->id}", $body)->assertOk();

    return $order->refresh();
}

function oedLastEdit(Order $order): OrderEvent
{
    return OrderEvent::query()->where('order_id', $order->id)->where('type', OrderEventType::Edited->value)->latest('id')->firstOrFail();
}

function oedCounter(string $series): int
{
    return (int) DB::table('invoice_number_sequences')->where('series', $series)->value('last_value');
}

/** @return list<array<string, mixed>> */
function oedLedger(): array
{
    return DB::table('financial_transactions')->orderBy('id')->get()->map(static fn ($r): array => (array) $r)->all();
}

/** Dispatch the order's current lines (sale_out) and move it to shipped, as the courier flow does. */
function oedShip(Order $order): Order
{
    DB::transaction(function () use ($order): void {
        $lines = $order->refresh()->reservedLines();
        ksort($lines);
        app(StockReservations::class)->release($lines);
        app(StockLedger::class)->record(array_map(
            static fn (int $variantId, int $qty): StockMovementLine => new StockMovementLine($variantId, StockMovementType::SaleOut, $qty, referenceType: 'order', referenceId: $order->id),
            array_keys($lines), $lines,
        ));
        OrderItem::query()->where('order_id', $order->id)->update(['quantity_shipped' => DB::raw('quantity')]);
        $t = app(OrderTransitions::class);
        if ($order->status !== OrderStatus::Confirmed) {
            $t->transition($order, OrderStatus::Confirmed);
        }
        $t->transition($order, OrderStatus::Shipped);
    });

    return $order->refresh()->load('items');
}

function oedDeliver(Order $order): Order
{
    $order = oedShip($order);
    DB::transaction(function () use ($order): void {
        $t = app(OrderTransitions::class);
        $t->transitionFulfillment($order, FulfillmentStatus::Fulfilled);
        $t->transition($order, OrderStatus::Delivered);
    });
    /** @var Payment $payment */
    $payment = Payment::query()->where('order_id', $order->id)->latest('id')->firstOrFail();
    app(CollectCodPayment::class)->handle($payment, $payment->amount);

    return $order->refresh()->load('items');
}

function oedLine(Order $order, int $variantId): OrderItem
{
    return $order->items()->where('variant_id', $variantId)->where('quantity', '>', 0)->sole();
}

/** Three variants: A ৳1,000, B ৳500, C ৳200 (cost 60 %). */
function oedVariants(): array
{
    return [
        CartTestKit::variant('1000.00', 20, variant: ['cost_price' => '600.00']),
        CartTestKit::variant('500.00', 20, variant: ['cost_price' => '300.00']),
        CartTestKit::variant('200.00', 20, variant: ['cost_price' => '120.00']),
    ];
}

it('credits a reduction made after the invoice was issued, line by line', function (): void {
    [$a, $b] = oedVariants();
    $order = oedPlace([$a->id => 2, $b->id => 1]);
    $invoice = app(IssueSalesInvoice::class)->handle($order);
    $before = $order->grand_total;

    $edited = oedEdit($order, [$a->id => 1]);
    $edit = oedLastEdit($order);

    $note = CreditNote::query()->sole();
    expect($note->number)->toBe("CN-{$this->year}-000001")
        ->and($note->reason)->toBe(CreditNoteReason::OrderEdited)
        ->and($note->source_type)->toBe('order_edit')
        ->and($note->source_id)->toBe($edit->id)
        ->and($note->source_reference)->toBe($order->number)
        ->and($note->invoice_id)->toBe($invoice->id)
        ->and($note->total->toDecimal())->toBe($before->minus($edited->grand_total)->toDecimal())
        ->and($note->total->toDecimal())->toBe('1500.00')
        ->and($note->shipping->toDecimal())->toBe('0.00')
        ->and($note->adjustment->toDecimal())->toBe('0.00')
        // toEqual: MySQL's JSON column does not keep key order.
        ->and($note->lines)->toEqual([
            ['kind' => 'item', 'name' => 'Remote Control Car', 'name_bn' => 'রিমোট কন্ট্রোল গাড়ি', 'variant' => $a->name, 'sku' => $a->sku, 'quantity' => 1, 'unit_price' => '1000.00', 'amount' => '1000.00'],
            ['kind' => 'item', 'name' => 'Remote Control Car', 'name_bn' => 'রিমোট কন্ট্রোল গাড়ি', 'variant' => $b->name, 'sku' => $b->sku, 'quantity' => 1, 'unit_price' => '500.00', 'amount' => '500.00'],
        ])
        ->and(Invoice::query()->where('kind', 'supplementary')->count())->toBe(0);

    $fresh = Invoice::query()->with(['creditNotes', 'supplementaryInvoices'])->findOrFail($invoice->id);
    expect($fresh->balance()->toDecimal())->toBe($edited->grand_total->toDecimal());
});

it('issues a supplementary invoice for an increase, numbered from the INV series and linked to the original', function (): void {
    [$a, , $c] = oedVariants();
    $order = oedPlace([$a->id => 1]);
    $invoice = app(IssueSalesInvoice::class)->handle($order);
    $before = $order->grand_total;

    $edited = oedEdit($order, [$a->id => 2, $c->id => 1]);
    $edit = oedLastEdit($order);

    $supplementary = Invoice::query()->where('kind', InvoiceKind::Supplementary->value)->sole();
    expect($supplementary->number)->toBe("INV-{$this->year}-000002")
        ->and($supplementary->type->value)->toBe('sales')
        ->and($supplementary->original_invoice_id)->toBe($invoice->id)
        ->and($supplementary->order_id)->toBe($order->id)
        ->and([$supplementary->source_type, $supplementary->source_id])->toBe(['order_edit', $edit->id])
        ->and($supplementary->total->toDecimal())->toBe($edited->grand_total->minus($before)->toDecimal())
        ->and($supplementary->total->toDecimal())->toBe('1200.00')
        ->and($supplementary->due->toDecimal())->toBe('1200.00')
        ->and($supplementary->paid->toDecimal())->toBe('0.00')
        ->and($supplementary->issued_to['original_invoice_number'])->toBe($invoice->number)
        ->and($supplementary->issued_to['name'])->toBe('রাহেলা খাতুন')
        ->and(array_map(static fn (array $l): array => [$l['kind'], $l['sku'], $l['quantity'], $l['unit_price'], $l['line_total']], $supplementary->lines))
        ->toBe([['item', $a->sku, 1, '1000.00', '1000.00'], ['item', $c->sku, 1, '200.00', '200.00']])
        ->and(CreditNote::query()->count())->toBe(0)
        ->and(oedCounter('INV'))->toBe(2);

    // The original's figures cover it (additive API fields); the supplementary row points back.
    $rows = collect($this->getJson('/api/v1/admin/invoices')->assertOk()->json('data'))->keyBy('id');
    expect($rows[$invoice->id])->toMatchArray([
        'kind' => 'original', 'total' => $before->toDecimal(), 'invoiced_total' => $edited->grand_total->toDecimal(),
        'credited' => '0.00', 'balance' => $edited->grand_total->toDecimal(), 'credit_status' => 'none',
    ])
        ->and($rows[$invoice->id]['supplementary_invoices'])->toHaveCount(1)
        ->and($rows[$invoice->id]['supplementary_invoices'][0])->toMatchArray(['id' => $supplementary->id, 'number' => $supplementary->number, 'total' => '1200.00', 'can_print' => true])
        ->and($rows[$supplementary->id])->toMatchArray(['kind' => 'supplementary', 'original_invoice_id' => $invoice->id, 'original_invoice_number' => $invoice->number, 'supplementary_invoices' => [], 'balance' => '1200.00']);

    expect($this->getJson('/api/v1/admin/invoices?filter[kind]=supplementary')->assertOk()->json('data.*.id'))->toBe([$supplementary->id])
        ->and($this->getJson('/api/v1/admin/invoices?filter[kind]=original')->assertOk()->json('data.*.id'))->toBe([$invoice->id]);
    $this->getJson('/api/v1/admin/invoices?filter[kind]=debit')->assertStatus(400);
    $this->getJson("/api/v1/admin/invoices/{$supplementary->id}")->assertOk()->assertJsonPath('original_invoice_number', $invoice->number)->assertJsonPath('lines.0.kind', 'item');
});

it('invoices the added lines and credits the delivery charge when an upward edit reaches free delivery', function (): void {
    [$a, , $c] = oedVariants();
    $order = oedPlace([$a->id => 1]);
    app(IssueSalesInvoice::class)->handle($order);
    $before = $order->grand_total;
    $shipping = $order->shipping_charge;
    expect($shipping->isZero())->toBeFalse();

    $edited = oedEdit($order, [$a->id => 3, $c->id => 1]); // subtotal ৳3,200: free delivery from ৳3,000

    $supplementary = Invoice::query()->where('kind', 'supplementary')->sole();
    $note = CreditNote::query()->sole();
    expect($edited->shipping_charge->isZero())->toBeTrue()
        ->and($supplementary->total->toDecimal())->toBe('2200.00')
        ->and($supplementary->shipping->toDecimal())->toBe('0.00')
        ->and($note->reason)->toBe(CreditNoteReason::OrderEdited)
        ->and($note->lines)->toBe([])
        ->and($note->shipping->toDecimal())->toBe($shipping->toDecimal())
        ->and($note->total->toDecimal())->toBe($shipping->toDecimal())
        ->and($supplementary->total->minus($note->total)->toDecimal())->toBe($edited->grand_total->minus($before)->toDecimal());
});

it('splits a mixed edit: removed line and lower delivery charge credited, added line invoiced', function (): void {
    [$a, $b, $c] = oedVariants();
    $order = oedPlace([$a->id => 1, $b->id => 2]);
    $invoice = app(IssueSalesInvoice::class)->handle($order);
    $before = $order->grand_total;
    $shippingBefore = $order->shipping_charge;

    // B removed, C added, delivered inside Dhaka instead (a cheaper zone).
    $dhaka = App\Domain\Customer\Models\District::query()->where('name', 'Dhaka')->firstOrFail();
    $edited = oedEdit($order, [$a->id => 1, $c->id => 3], ['district_id' => $dhaka->id, 'upazila_id' => null]);
    expect($edited->shipping_charge->lessThan($shippingBefore))->toBeTrue();

    $note = CreditNote::query()->sole();
    $supplementary = Invoice::query()->where('kind', 'supplementary')->sole();
    $shippingDrop = $shippingBefore->minus($edited->shipping_charge);
    expect($note->lines)->toHaveCount(1)
        ->and($note->lines[0])->toMatchArray(['kind' => 'item', 'sku' => $b->sku, 'quantity' => 2, 'amount' => '1000.00'])
        ->and($note->shipping->toDecimal())->toBe($shippingDrop->toDecimal())
        ->and($note->total->toDecimal())->toBe(Money::of('1000.00')->plus($shippingDrop)->toDecimal())
        ->and($supplementary->lines)->toHaveCount(1)
        ->and($supplementary->lines[0])->toMatchArray(['kind' => 'item', 'sku' => $c->sku, 'quantity' => 3, 'line_total' => '600.00'])
        ->and($supplementary->shipping->toDecimal())->toBe('0.00')
        ->and($supplementary->total->toDecimal())->toBe('600.00')
        // The two documents together are exactly the edit's change in the order total.
        ->and($supplementary->total->minus($note->total)->toDecimal())->toBe($edited->grand_total->minus($before)->toDecimal())
        ->and($note->source_id)->toBe($supplementary->source_id);

    $fresh = Invoice::query()->with(['creditNotes', 'supplementaryInvoices'])->findOrFail($invoice->id);
    expect($fresh->balance()->toDecimal())->toBe($edited->grand_total->toDecimal());
});

it('credits the full new balance when an order edited upwards is then cancelled', function (): void {
    [$a, $b] = oedVariants();
    $order = oedPlace([$a->id => 1]);
    $invoice = app(IssueSalesInvoice::class)->handle($order);
    $edited = oedEdit($order, [$a->id => 1, $b->id => 2]);
    $supplementary = Invoice::query()->where('kind', 'supplementary')->sole();

    app(CancelOrder::class)->handle($edited, CancellationReason::CustomerRequest);

    $note = CreditNote::query()->sole();
    expect($note->reason)->toBe(CreditNoteReason::OrderCancelled)
        ->and($note->invoice_id)->toBe($invoice->id)
        ->and($note->total->toDecimal())->toBe($invoice->total->plus($supplementary->total)->toDecimal())
        ->and($note->total->toDecimal())->toBe($edited->grand_total->toDecimal())
        ->and($note->lines)->toHaveCount(1)
        ->and($note->lines[0])->toMatchArray(['kind' => 'balance', 'name' => $invoice->number, 'amount' => $edited->grand_total->toDecimal()]);

    $fresh = Invoice::query()->with(['creditNotes', 'supplementaryInvoices'])->findOrFail($invoice->id);
    expect($fresh->balance()->toDecimal())->toBe('0.00');
    $this->getJson('/api/v1/admin/invoices')->assertOk()->assertJsonPath('data.1.credit_status', 'full');
});

it('credits a refund after an upward edit, capped by the balance including the supplementary invoice', function (): void {
    [$a] = oedVariants();
    $order = oedPlace([$a->id => 1]);
    $invoice = app(IssueSalesInvoice::class)->handle($order);
    $order = oedDeliver(oedEdit($order, [$a->id => 3]));
    $line = oedLine($order, $a->id);

    // Return all three units and refund them (৳3,000 — more than the original invoice alone).
    $this->postJson("/api/v1/admin/orders/{$order->id}/returns", ['items' => [['order_item_id' => $line->id, 'quantity' => 3]], 'reason' => 'defective', 'refund_shipping' => false])->assertCreated();
    $return = OrderReturn::query()->latest('id')->firstOrFail();
    $this->postJson("/api/v1/admin/returns/{$return->id}/inspect", ['items' => [['order_item_id' => $line->id, 'resellable' => 3, 'damaged' => 0]]])->assertOk();
    $refund = $return->refresh()->refund;
    $this->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertOk();
    $this->postJson("/api/v1/admin/refunds/{$refund->id}/pay", ['method' => 'bkash', 'reference' => 'BK'.Str::random(6)], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

    $note = CreditNote::query()->where('reason', 'refund')->sole();
    expect($invoice->total->lessThan(Money::of('3000.00')))->toBeTrue()
        ->and($note->total->toDecimal())->toBe('3000.00')
        ->and($note->lines[0])->toMatchArray(['sku' => $a->sku, 'quantity' => 3, 'amount' => '3000.00']);
    $fresh = Invoice::query()->with(['creditNotes', 'supplementaryInvoices'])->findOrFail($invoice->id);
    expect($fresh->balance()->toDecimal())->toBe($order->shipping_charge->toDecimal());
});

it('credits the whole remaining balance when the courier returns an invoiced order (RTO)', function (): void {
    [$a, $b] = oedVariants();
    $order = oedPlace([$a->id => 2, $b->id => 1]);
    $invoice = app(IssueSalesInvoice::class)->handle($order);
    $order = oedShip($order);

    app(RecordCourierReturn::class)->handle($order->id, 991, 'SHP-26-000991');

    $note = CreditNote::query()->sole();
    expect($order->refresh()->status)->toBe(OrderStatus::Returned)
        ->and($note->number)->toBe("CN-{$this->year}-000001")
        ->and($note->reason)->toBe(CreditNoteReason::CourierReturn)
        ->and([$note->source_type, $note->source_id])->toBe(['courier_return', $order->id])
        ->and($note->total->toDecimal())->toBe($invoice->total->toDecimal())
        ->and($note->shipping->toDecimal())->toBe($invoice->shipping->toDecimal())
        ->and(array_column($note->lines, 'amount'))->toBe(array_column($invoice->lines, 'line_total'))
        ->and(Invoice::query()->with(['creditNotes', 'supplementaryInvoices'])->findOrFail($invoice->id)->balance()->toDecimal())->toBe('0.00');
});

it('credits an RTO after an upward edit with one balance line for original plus supplementary', function (): void {
    [$a, $b] = oedVariants();
    $order = oedPlace([$a->id => 1]);
    $invoice = app(IssueSalesInvoice::class)->handle($order);
    $order = oedShip(oedEdit($order, [$a->id => 1, $b->id => 1]));

    app(RecordCourierReturn::class)->handle($order->id, 992, 'SHP-26-000992');

    $note = CreditNote::query()->sole();
    expect($note->total->toDecimal())->toBe($order->grand_total->toDecimal())
        ->and($note->lines)->toHaveCount(1)
        ->and($note->lines[0]['kind'])->toBe('balance');
});

it('issues nothing on a courier return without an invoice, or on a customer return', function (): void {
    [$a] = oedVariants();
    $order = oedShip(oedPlace([$a->id => 1]));
    app(RecordCourierReturn::class)->handle($order->id, 993, 'SHP-26-000993');
    expect(CreditNote::query()->count())->toBe(0)->and(oedCounter('CN'))->toBe(0);

    // A `returned` order whose move was not the courier's (customer return path): nothing.
    $other = oedPlace([$a->id => 1]);
    app(IssueSalesInvoice::class)->handle($other);
    $other = oedDeliver($other);
    DB::transaction(fn () => app(OrderTransitions::class)->transition($other, OrderStatus::Returned));
    expect(app(IssueCourierReturnCreditNote::class)->handle($other->id))->toBeNull()
        ->and(CreditNote::query()->where('reason', 'courier_return')->count())->toBe(0);
});

it('issues nothing on a courier return when a cancellation already credited the invoice', function (): void {
    [$a] = oedVariants();
    $order = oedPlace([$a->id => 1]);
    app(IssueSalesInvoice::class)->handle($order);
    app(CancelOrder::class)->handle($order, CancellationReason::CustomerRequest);
    expect(CreditNote::query()->count())->toBe(1);

    // As if a late courier "returned" reached the order anyway (a cancelled order cannot move
    // to returned through the state machine; the guard is the zero balance).
    DB::table('orders')->where('id', $order->id)->update(['status' => 'returned']);
    OrderEvent::query()->create(['order_id' => $order->id, 'type' => OrderEventType::StatusChanged, 'field' => 'status', 'from_status' => 'cancelled', 'to_status' => 'returned', 'meta' => ['courier_return' => true], 'is_customer_visible' => false]);

    app(IssueCreditNoteOnOrderReturned::class)->handle(new OrderReturned($order->id, $order->customer_id, 'shipped', 'returned'));

    expect(CreditNote::query()->count())->toBe(1)
        ->and(CreditNote::query()->sole()->reason)->toBe(CreditNoteReason::OrderCancelled)
        ->and(oedCounter('CN'))->toBe(1);
});

it('never issues twice when an edit or a return is delivered again', function (): void {
    [$a, $b, $c] = oedVariants();
    $order = oedPlace([$a->id => 1, $b->id => 1]);
    app(IssueSalesInvoice::class)->handle($order);
    $edited = oedEdit($order, [$a->id => 2, $c->id => 1]); // B off (credit), A and C up (supplementary)
    $edit = oedLastEdit($order);
    expect(CreditNote::query()->count())->toBe(1)->and(Invoice::query()->where('kind', 'supplementary')->count())->toBe(1);

    $replay = new OrderEdited($order->id, $order->customer_id, ['lines', 'totals'], '0.00', $edited->grand_total->toDecimal());
    app(IssueDocumentsOnOrderEdited::class)->handle($replay);
    app(IssueDocumentsOnOrderEdited::class)->handle($replay);
    $again = app(IssueOrderEditDocuments::class)->handle($edit->id);

    expect(CreditNote::query()->count())->toBe(1)
        ->and(Invoice::query()->where('kind', 'supplementary')->count())->toBe(1)
        ->and($again['credit_note']?->id)->toBe(CreditNote::query()->sole()->id)
        ->and($again['supplementary']?->id)->toBe(Invoice::query()->where('kind', 'supplementary')->sole()->id)
        ->and(oedCounter('CN'))->toBe(1)
        ->and(oedCounter('INV'))->toBe(2);

    // RTO replay.
    $shipped = oedPlace([$a->id => 1]);
    app(IssueSalesInvoice::class)->handle($shipped);
    $shipped = oedShip($shipped);
    app(RecordCourierReturn::class)->handle($shipped->id, 994, 'SHP-26-000994');
    $event = new OrderReturned($shipped->id, $shipped->customer_id, 'shipped', 'returned');
    app(IssueCreditNoteOnOrderReturned::class)->handle($event);
    app(IssueCreditNoteOnOrderReturned::class)->handle($event);

    expect(CreditNote::query()->where('reason', 'courier_return')->count())->toBe(1)
        ->and(app(IssueCourierReturnCreditNote::class)->handle($shipped->id)?->sequence)->toBe(2)
        ->and(oedCounter('CN'))->toBe(2);
});

it('issues nothing for an edit the invoice already shows, or one that moved no money', function (): void {
    [$a] = oedVariants();
    // Edited BEFORE the first print: the invoice snapshot already has it.
    $order = oedPlace([$a->id => 1]);
    $edited = oedEdit($order, [$a->id => 2]);
    $invoice = app(IssueSalesInvoice::class)->handle($edited);
    expect($invoice->total->toDecimal())->toBe($edited->grand_total->toDecimal())
        ->and($invoice->order_event_id)->toBe(oedLastEdit($order)->id)
        ->and(app(IssueOrderEditDocuments::class)->forOrder($order->id))->toBe([])
        ->and(app(IssueOrderEditDocuments::class)->handle(oedLastEdit($order)->id))->toBe(['credit_note' => null, 'supplementary' => null]);

    // After the print, only the landmark changes: no money moved, no document.
    oedEdit($edited, [$a->id => 2], ['landmark' => 'নতুন মোড়, স্কুলের সামনে']);
    expect(oedLastEdit($order)->meta['changed'])->toBe(['address'])
        ->and(CreditNote::query()->count())->toBe(0)
        ->and(Invoice::query()->count())->toBe(1)
        ->and(oedCounter('CN'))->toBe(0)
        ->and(oedCounter('INV'))->toBe(1);
});

it('keeps the INV and CN series gap-free across originals, supplementary invoices and a rollback', function (): void {
    [$a, $b] = oedVariants();
    $one = oedPlace([$a->id => 1]);
    $two = oedPlace([$a->id => 1]);
    app(IssueSalesInvoice::class)->handle($one);                      // INV 1
    app(IssueSalesInvoice::class)->handle($two);                      // INV 2

    Event::fake([OrderEdited::class]);
    oedEdit($one, [$a->id => 1, $b->id => 1]);
    $edit = oedLastEdit($one);
    try {
        DB::transaction(function () use ($edit): void {
            app(IssueOrderEditDocuments::class)->handle($edit->id);
            throw new RuntimeException('printer jammed');
        });
    } catch (RuntimeException) {
    }
    expect(oedCounter('INV'))->toBe(2)->and(Invoice::query()->count())->toBe(2);

    app(IssueOrderEditDocuments::class)->handle($edit->id);           // INV 3 (supplementary)
    $three = oedPlace([$a->id => 1]);
    app(IssueSalesInvoice::class)->handle($three);                    // INV 4
    oedEdit($two, [$a->id => 1, $b->id => 2]);
    app(IssueOrderEditDocuments::class)->forOrder($two->id);          // INV 5 (supplementary)
    oedEdit($two, [$b->id => 2]);
    app(IssueOrderEditDocuments::class)->forOrder($two->id);          // CN 1

    $numbers = Invoice::query()->orderBy('id')->pluck('number')->all();
    expect($numbers)->toBe(array_map(fn (int $n): string => sprintf('INV-%s-%06d', $this->year, $n), [1, 2, 3, 4, 5]))
        ->and(Invoice::query()->orderBy('id')->pluck('kind')->map(fn ($k) => $k->value)->all())->toBe(['original', 'original', 'supplementary', 'original', 'supplementary'])
        ->and(CreditNote::query()->pluck('number')->all())->toBe(["CN-{$this->year}-000001"])
        ->and(oedCounter('INV'))->toBe(5)
        ->and(oedCounter('CN'))->toBe(1);
});

it('allows one original invoice per order in the schema, and any number of supplementary ones', function (): void {
    [$a] = oedVariants();
    $order = oedPlace([$a->id => 1]);
    $invoice = app(IssueSalesInvoice::class)->handle($order);
    $row = static fn (string $kind, int $seq): array => [
        'type' => 'sales', 'kind' => $kind, 'series' => 'INV', 'year' => 1999, 'sequence' => $seq, 'number' => "X-{$seq}",
        'order_id' => $order->id, 'original_invoice_id' => $kind === 'supplementary' ? $invoice->id : null,
        'reference' => $order->number, 'issued_to' => '{}', 'issued_by' => '{}', 'lines' => '[]', 'issued_at' => now(),
    ];

    DB::table('invoices')->insert($row('supplementary', 1));
    DB::table('invoices')->insert($row('supplementary', 2));
    expect(fn () => DB::table('invoices')->insert($row('original', 3)))->toThrow(UniqueConstraintViolationException::class)
        ->and(Invoice::query()->where('order_id', $order->id)->count())->toBe(3);
});

it('never writes the ledger when it issues edit documents or an RTO credit note', function (): void {
    [$a, $b, $c] = oedVariants();
    $order = oedPlace([$a->id => 1, $b->id => 1]);
    app(IssueSalesInvoice::class)->handle($order);
    Event::fake([OrderEdited::class, OrderReturned::class]);
    oedEdit($order, [$a->id => 2, $c->id => 1]);

    $before = oedLedger();
    $docs = app(IssueOrderEditDocuments::class)->forOrder($order->id);
    expect($docs[0]['credit_note'])->not->toBeNull()
        ->and($docs[0]['supplementary'])->not->toBeNull()
        ->and(oedLedger())->toBe($before);

    $shipped = oedPlace([$a->id => 1]);
    app(IssueSalesInvoice::class)->handle($shipped);
    $shipped = oedShip($shipped);
    app(RecordCourierReturn::class)->handle($shipped->id, 995, 'SHP-26-000995');
    $before = oedLedger();
    expect(app(IssueCourierReturnCreditNote::class)->handle($shipped->id))->not->toBeNull()
        ->and(oedLedger())->toBe($before);
});

it('prints the supplementary invoice and the new credit notes in English and Bangla', function (): void {
    [$a, $b, $c] = oedVariants();
    $order = oedPlace([$a->id => 1, $b->id => 1]);
    $invoice = app(IssueSalesInvoice::class)->handle($order);
    oedEdit($order, [$a->id => 1, $c->id => 2]);
    $supplementary = Invoice::query()->where('kind', 'supplementary')->sole();
    $edited = CreditNote::query()->sole();

    $en = PdfText::extract((string) $this->get("/api/v1/admin/invoices/{$supplementary->id}/pdf?locale=en")->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent());
    expect($en)->toContain('Supplementary Invoice')->toContain($supplementary->number)->toContain($invoice->number)
        ->toContain($c->sku)->toContain('400')->toContain('Total added')->toContain('Collect on delivery (in addition)');

    $bnPdf = (string) $this->get("/api/v1/admin/invoices/{$supplementary->id}/pdf?locale=bn")->assertOk()->getContent();
    $bn = PdfText::extract($bnPdf);
    expect($bnPdf)->toContain('HindSiliguri')
        ->and($bn)->toContain($supplementary->number)->toContain($invoice->number)
                // "ম্প" is one shaped conjunct glyph (private use), so the title is matched after it.
        ->and(PdfText::banglaLetters($bn))->toContain(PdfText::banglaLetters('রক ইনভয়েস'))
        ->toContain(PdfText::banglaLetters('মূল ইনভয়েস'))
        ->toContain(PdfText::banglaLetters('রিমোট'))->toContain(PdfText::banglaLetters('গাড়ি'));

    $cnEn = PdfText::extract((string) $this->get("/api/v1/admin/credit-notes/{$edited->id}/pdf?locale=en")->assertOk()->getContent());
    expect($cnEn)->toContain('Credit Note')->toContain($edited->number)->toContain('Order edited after the invoice was issued')->toContain($b->sku);
    $cnBn = PdfText::extract((string) $this->get("/api/v1/admin/credit-notes/{$edited->id}/pdf?locale=bn")->assertOk()->getContent());
    // A reph (র্) is shaped onto the next letter and does not come back out of the PDF.
    expect(PdfText::banglaLetters($cnBn))->toContain(PdfText::banglaLetters(str_replace('র্', '', 'অর্ডার পরিবর্তন হয়েছে')));

    $shipped = oedPlace([$a->id => 1]);
    app(IssueSalesInvoice::class)->handle($shipped);
    app(RecordCourierReturn::class)->handle(oedShip($shipped)->id, 996, 'SHP-26-000996');
    $rto = CreditNote::query()->where('reason', 'courier_return')->sole();
    expect(PdfText::extract((string) $this->get("/api/v1/admin/credit-notes/{$rto->id}/pdf?locale=en")->assertOk()->getContent()))->toContain('Returned by the courier (RTO)')
        ->and(PdfText::banglaLetters(PdfText::extract((string) $this->get("/api/v1/admin/credit-notes/{$rto->id}/pdf?locale=bn")->assertOk()->getContent())))
        ->toContain(PdfText::banglaLetters('কুরিয়ার ফেরত দিয়েছে'));
    $this->getJson('/api/v1/admin/credit-notes?filter[reason]=courier_return')->assertOk()->assertJsonPath('data.0.reason', 'courier_return');
    $this->getJson('/api/v1/admin/credit-notes?filter[reason]=order_edited')->assertOk()->assertJsonPath('data.0.id', $edited->id);
});

it('gives a supplementary invoice and an edit credit note Bangla words and the logo (P9)', function (): void {
    Tests\Support\InvoiceLogoKit::fakeDisk();
    Tests\Support\InvoiceLogoKit::choose(Tests\Support\InvoiceLogoKit::media());
    [$a, $b, $c] = oedVariants();
    $order = oedPlace([$a->id => 1, $b->id => 1]);
    app(IssueSalesInvoice::class)->handle($order);

    oedEdit($order, [$a->id => 1, $c->id => 4]);

    $supplementary = Invoice::query()->where('kind', InvoiceKind::Supplementary->value)->sole();
    $note = CreditNote::query()->sole();
    expect($supplementary->amount_in_words_bn)->toBe(App\Domain\Invoicing\Services\AmountInWords::bangla($supplementary->total))
        ->and($note->amount_in_words_bn)->toBe(App\Domain\Invoicing\Services\AmountInWords::bangla($note->total))
        ->and($supplementary->issued_by['logo'])->not->toBeNull();

    foreach (['en', 'bn'] as $locale) {
        $pdf = (string) $this->get("/api/v1/admin/invoices/{$supplementary->id}/pdf?locale={$locale}")->assertOk()->getContent();
        expect(Tests\Support\InvoiceLogoKit::imageCount($pdf))->toBeGreaterThan(0);
        if ($locale === 'bn') {
            expect(PdfText::banglaLetters(PdfText::extract($pdf)))->toContain(PdfText::banglaLetters('কথায়'));
        }
    }
});
