<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\IssueCancellationCreditNote;
use App\Domain\Invoicing\Actions\IssueRefundCreditNote;
use App\Domain\Invoicing\Actions\IssueSalesInvoice;
use App\Domain\Invoicing\Enums\CreditNoteReason;
use App\Domain\Invoicing\Exceptions\CreditNoteExceedsInvoice;
use App\Domain\Invoicing\Exceptions\CreditNoteImmutable;
use App\Domain\Invoicing\Listeners\IssueCreditNoteOnOrderCancelled;
use App\Domain\Invoicing\Listeners\IssueCreditNoteOnRefundPaid;
use App\Domain\Invoicing\Models\CreditNote;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Ordering\Actions\CancelOrder;
use App\Domain\Ordering\Enums\CancellationReason;
use App\Domain\Ordering\Events\OrderCancelled;
use App\Domain\Ordering\Events\RefundPaid;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderReturn;
use App\Domain\Payment\Gateways\NullGateway;
use App\Domain\Payment\Models\Refund;
use App\Models\Enums\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Ordering\Support\OrderFixtures;
use Tests\Feature\Returns\ReturnsKit;

/*
 * P8 batch 2 credit notes: an issued customer invoice is corrected by a CN-YY-NNNNNN credit
 * note when the order is cancelled after issue (the whole uncredited remainder) or a refund
 * is paid on a return (the refunded lines + delivery charge). Gap-free per Dhaka year,
 * idempotent per cause, never more than the invoice, immutable, and never a ledger row.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '', 'returns.approval_threshold' => '100000.00']);
    NullGateway::reset();
});

/** A placed (pending) COD order of A ×2 @ 1250 + B ×1 @ 500 + 70 delivery = 3070, invoiced. */
function cnPlacedInvoicedOrder(): array
{
    $a = ReturnsKit::variant(10, '1250.00', '800.00');
    $b = ReturnsKit::variant(10, '500.00', '300.00');
    $order = OrderFixtures::place([$a->id => 2, $b->id => 1]);
    $invoice = app(IssueSalesInvoice::class)->handle($order);

    return [$order->refresh(), $invoice];
}

/** A delivered, COD-collected order of A ×2 + B ×1 + 70 = 3070, invoiced. */
function cnDeliveredInvoicedOrder(): array
{
    $a = ReturnsKit::variant(10, '1250.00', '800.00');
    $b = ReturnsKit::variant(10, '500.00', '300.00');
    $order = ReturnsKit::deliveredOrder([$a->id => 2, $b->id => 1]);
    $invoice = app(IssueSalesInvoice::class)->handle($order);

    return [$order, $invoice, ReturnsKit::line($order, $a->id), ReturnsKit::line($order, $b->id)];
}

/** Return → inspect (all resellable) → approve → pay by bKash, through the API, as the manager. */
function cnRefund(Order $order, array $lines, bool $refundShipping = false): Refund
{
    test()->postJson("/api/v1/admin/orders/{$order->id}/returns", [
        'items' => array_map(static fn (array $l): array => ['order_item_id' => $l[0], 'quantity' => $l[1]], $lines),
        'reason' => 'defective',
        'refund_shipping' => $refundShipping,
    ])->assertCreated();
    $return = OrderReturn::query()->latest('id')->firstOrFail();
    test()->postJson("/api/v1/admin/returns/{$return->id}/inspect", [
        'items' => array_map(static fn (array $l): array => ['order_item_id' => $l[0], 'resellable' => $l[1], 'damaged' => 0], $lines),
    ])->assertOk();
    $refund = $return->refresh()->refund;
    test()->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertOk();
    test()->postJson("/api/v1/admin/refunds/{$refund->id}/pay", ['method' => 'bkash', 'reference' => 'BK'.Str::random(6)], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertOk()->assertJsonPath('status', 'completed');

    return $refund->refresh();
}

/** @return list<array<string, mixed>> every ledger row, as stored */
function cnLedger(): array
{
    return DB::table('financial_transactions')->orderBy('id')->get()->map(static fn ($r): array => (array) $r)->all();
}

function cnCounter(): int
{
    return (int) DB::table('invoice_number_sequences')->where('series', 'CN')->value('last_value');
}

it('credits the whole invoice when the order is cancelled after issue', function (): void {
    [$order, $invoice] = cnPlacedInvoicedOrder();
    $year = now()->setTimezone('Asia/Dhaka')->format('y');

    app(CancelOrder::class)->handle($order, CancellationReason::CustomerRequest);

    $note = CreditNote::query()->sole();
    expect($note->number)->toBe("CN-{$year}-000001")
        ->and($note->reason)->toBe(CreditNoteReason::OrderCancelled)
        ->and($note->source_type)->toBe('order_cancellation')
        ->and($note->source_id)->toBe($order->id)
        ->and($note->source_reference)->toBe($order->number)
        ->and($note->invoice_id)->toBe($invoice->id)
        ->and($note->invoice_number)->toBe($invoice->number)
        ->and($note->total->toDecimal())->toBe('3070.00')
        ->and($note->subtotal->toDecimal())->toBe($invoice->subtotal->toDecimal())
        ->and($note->shipping->toDecimal())->toBe('70.00')
        ->and($note->adjustment->toDecimal())->toBe('0.00')
        ->and(array_column($note->lines, 'quantity'))->toBe(array_column($invoice->lines, 'quantity'))
        ->and(array_column($note->lines, 'amount'))->toBe(array_column($invoice->lines, 'line_total'))
        ->and($note->issued_to['name'])->toBe($invoice->issued_to['name'])
        ->and($note->amount_in_words)->toBe(Invoice::query()->findOrFail($invoice->id)->amount_in_words);

    $fresh = Invoice::query()->with('creditNotes')->findOrFail($invoice->id);
    expect($fresh->credited()->toDecimal())->toBe('3070.00')->and($fresh->balance()->toDecimal())->toBe('0.00');
});

it('issues nothing when an order with no invoice is cancelled', function (): void {
    $a = ReturnsKit::variant(10, '1250.00', '800.00');
    $order = OrderFixtures::place([$a->id => 1]);

    app(CancelOrder::class)->handle($order, CancellationReason::CustomerRequest);

    expect(CreditNote::query()->count())->toBe(0)
        ->and(app(IssueCancellationCreditNote::class)->handle($order->id))->toBeNull()
        ->and(cnCounter())->toBe(0);
});

it('credits a partial refund, then the rest, and the invoice balance reaches zero', function (): void {
    ReturnsKit::actingAs(Role::Manager);
    [$order, $invoice, $lineA, $lineB] = cnDeliveredInvoicedOrder();
    $year = now()->setTimezone('Asia/Dhaka')->format('y');

    $first = cnRefund($order, [[$lineA->id, 1]]);
    $one = CreditNote::query()->where('source_id', $first->id)->where('source_type', 'refund')->sole();
    expect($one->number)->toBe("CN-{$year}-000001")
        ->and($one->total->toDecimal())->toBe('1250.00')
        ->and($one->shipping->toDecimal())->toBe('0.00')
        ->and($one->lines)->toHaveCount(1)
        ->and($one->lines[0])->toMatchArray(['kind' => 'item', 'sku' => $lineA->sku, 'quantity' => 1, 'unit_price' => '1250.00', 'amount' => '1250.00'])
        ->and($one->source_reference)->toStartWith('RMA-');

    $second = cnRefund($order, [[$lineA->id, 1], [$lineB->id, 1]], refundShipping: true);
    $two = CreditNote::query()->where('source_id', $second->id)->where('source_type', 'refund')->sole();
    expect($two->number)->toBe("CN-{$year}-000002")
        ->and($two->total->toDecimal())->toBe('1820.00')
        ->and($two->subtotal->toDecimal())->toBe('1750.00')
        ->and($two->shipping->toDecimal())->toBe('70.00')
        ->and($two->total->toDecimal())->toBe($second->amount->toDecimal());

    $fresh = Invoice::query()->with('creditNotes')->findOrFail($invoice->id);
    expect($fresh->credited()->toDecimal())->toBe('3070.00')
        ->and($fresh->balance()->toDecimal())->toBe('0.00')
        ->and($fresh->creditNotes->pluck('number')->all())->toBe(["CN-{$year}-000001", "CN-{$year}-000002"]);
});

it('credits only the remaining balance when a partly credited order is cancelled', function (): void {
    [$order, $invoice] = cnPlacedInvoicedOrder();
    // A credit note already took 1000 of the 3070 (as a refund credit would).
    DB::table('credit_notes')->insert([
        'series' => 'CN', 'year' => 2000, 'sequence' => 1, 'number' => 'CN-00-000001', 'invoice_id' => $invoice->id, 'order_id' => $order->id,
        'invoice_number' => $invoice->number, 'invoice_issued_at' => now(), 'reason' => 'refund', 'source_type' => 'refund', 'source_id' => 999,
        'issued_to' => '{}', 'issued_by' => '{}', 'lines' => '[]', 'subtotal' => '1000.00', 'total' => '1000.00', 'issued_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    app(CancelOrder::class)->handle($order, CancellationReason::CustomerRequest);

    $note = CreditNote::query()->where('source_type', 'order_cancellation')->sole();
    expect($note->total->toDecimal())->toBe('2070.00')
        ->and($note->lines)->toHaveCount(1)
        ->and($note->lines[0])->toMatchArray(['kind' => 'balance', 'name' => $invoice->number, 'quantity' => 1, 'amount' => '2070.00']);
});

it('refuses to credit more than the invoice still has uncredited, and keeps the series gap-free', function (): void {
    ReturnsKit::actingAs(Role::Manager);
    [$order, $invoice, $lineA] = cnDeliveredInvoicedOrder();
    // As if the order had been edited upwards after its invoice was issued.
    DB::table('invoices')->where('id', $invoice->id)->update(['total' => '1000.00']);

    $refund = cnRefund($order, [[$lineA->id, 1]]); // ৳1,250 paid; the listener refuses, the payout stands

    expect($refund->status->value)->toBe('completed')
        ->and(CreditNote::query()->count())->toBe(0)
        ->and(fn () => app(IssueRefundCreditNote::class)->handle($refund->id))->toThrow(CreditNoteExceedsInvoice::class)
        ->and(CreditNote::query()->count())->toBe(0)
        ->and(cnCounter())->toBe(0);
});

it('never issues twice when the events are delivered again', function (): void {
    ReturnsKit::actingAs(Role::Manager);
    [$order, , $lineA] = cnDeliveredInvoicedOrder();
    $refund = cnRefund($order, [[$lineA->id, 1]]);
    expect(CreditNote::query()->count())->toBe(1);

    // Re-delivered to the Invoicing listener (the other domains' listeners have their own guards).
    $paid = new RefundPaid($refund->id, $order->id, $refund->amount->toDecimal(), 'bkash', '2026-11-13');
    app(IssueCreditNoteOnRefundPaid::class)->handle($paid);
    app(IssueCreditNoteOnRefundPaid::class)->handle($paid);
    expect(CreditNote::query()->count())->toBe(1)->and(cnCounter())->toBe(1);

    [$placed] = cnPlacedInvoicedOrder();
    app(CancelOrder::class)->handle($placed, CancellationReason::CustomerRequest);
    $replay = new OrderCancelled($placed->id, $placed->customer_id, 'pending', 'customer_request', null, null, '0.00');
    app(IssueCreditNoteOnOrderCancelled::class)->handle($replay);
    app(IssueCreditNoteOnOrderCancelled::class)->handle($replay);

    expect(CreditNote::query()->where('source_type', 'order_cancellation')->count())->toBe(1)
        ->and(app(IssueCancellationCreditNote::class)->handle($placed->id)?->sequence)->toBe(2)
        ->and(cnCounter())->toBe(2);
});

it('numbers credit notes gap-free per Dhaka year', function (): void {
    // 23:00 on 31 Dec in Dhaka (17:00 UTC) is still 2026 …
    Carbon::setTestNow(Carbon::parse('2026-12-31 17:00:00', 'UTC'));
    [$a] = cnPlacedInvoicedOrder();
    app(CancelOrder::class)->handle($a, CancellationReason::CustomerRequest);
    [$b] = cnPlacedInvoicedOrder();
    app(CancelOrder::class)->handle($b, CancellationReason::CustomerRequest);

    // … 00:30 on 1 Jan in Dhaka (18:30 UTC, still 31 Dec in UTC) starts 2027 at 1.
    Carbon::setTestNow(Carbon::parse('2026-12-31 18:30:00', 'UTC'));
    [$c] = cnPlacedInvoicedOrder();
    app(CancelOrder::class)->handle($c, CancellationReason::CustomerRequest);
    Carbon::setTestNow();

    expect(CreditNote::query()->orderBy('id')->pluck('number')->all())->toBe(['CN-26-000001', 'CN-26-000002', 'CN-27-000001'])
        ->and(DB::table('invoice_number_sequences')->where('series', 'CN')->orderBy('year')->pluck('last_value', 'year')->all())->toBe([2026 => 2, 2027 => 1]);
});

it('returns the number to the series when the issuing transaction rolls back', function (): void {
    [$a] = cnPlacedInvoicedOrder();
    [$b] = cnPlacedInvoicedOrder();
    Event::fake([OrderCancelled::class]);
    app(CancelOrder::class)->handle($a, CancellationReason::CustomerRequest);
    app(CancelOrder::class)->handle($b, CancellationReason::CustomerRequest);

    try {
        DB::transaction(function () use ($a): void {
            app(IssueCancellationCreditNote::class)->handle($a->id);
            throw new RuntimeException('printer jammed');
        });
    } catch (RuntimeException) {
    }

    expect(app(IssueCancellationCreditNote::class)->handle($b->id)?->sequence)->toBe(1)
        ->and(CreditNote::query()->count())->toBe(1)
        ->and(cnCounter())->toBe(1);
});

it('never edits or deletes an issued credit note', function (): void {
    [$order] = cnPlacedInvoicedOrder();
    app(CancelOrder::class)->handle($order, CancellationReason::CustomerRequest);
    $note = CreditNote::query()->sole();

    expect(fn () => $note->update(['total' => '1.00']))->toThrow(CreditNoteImmutable::class)
        ->and(fn () => $note->delete())->toThrow(CreditNoteImmutable::class)
        ->and(CreditNote::query()->sole()->total->toDecimal())->toBe('3070.00');
});

it('never writes the ledger when it issues a credit note', function (): void {
    ReturnsKit::actingAs(Role::Manager);

    // Cancellation: the order is cancelled first (its own ledger effects, if any), then credited.
    [$placed] = cnPlacedInvoicedOrder();
    Event::fake([OrderCancelled::class, RefundPaid::class]);
    app(CancelOrder::class)->handle($placed, CancellationReason::CustomerRequest);
    $before = cnLedger();
    expect(app(IssueCancellationCreditNote::class)->handle($placed->id))->not->toBeNull()
        ->and(cnLedger())->toBe($before);

    // Refund: Finance's listener is faked away with the event, so only the credit note runs.
    [$order, , $lineA] = cnDeliveredInvoicedOrder();
    $refund = cnRefund($order, [[$lineA->id, 1]]);
    $before = cnLedger();
    expect(app(IssueRefundCreditNote::class)->handle($refund->id)?->total->toDecimal())->toBe('1250.00')
        ->and(cnLedger())->toBe($before)
        ->and(CreditNote::query()->count())->toBe(2);
});
