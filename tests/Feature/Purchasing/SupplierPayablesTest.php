<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Finance\Services\FinanceInvariant;
use App\Domain\Purchasing\Actions\RecordSupplierPayment;
use App\Domain\Purchasing\DTOs\SupplierPaymentData;
use App\Domain\Purchasing\Enums\SupplierPaymentMethod;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierPayment;
use App\Domain\Purchasing\Services\SupplierBalances;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Purchasing\PurchasingKit as Kit;

/*
 * Supplier payables (PRD N-10): balance = opening + received − paid, and every payment
 * writes one `supplier_payment` ledger entry — cash out, never revenue (rule 10).
 * Receiving stock writes nothing to the ledger: cost reaches the P&L as COGS on sale.
 */

function pay(Supplier $supplier, string $amount, ?int $purchaseId = null, string $date = '2026-11-12'): SupplierPayment
{
    return app(RecordSupplierPayment::class)->handle($supplier, new SupplierPaymentData(
        Money::of($amount), SupplierPaymentMethod::Bkash, CarbonImmutable::parse($date), $purchaseId, 'TXN-8ABC12'
    ));
}

beforeEach(function (): void {
    $this->supplier = Supplier::factory()->create(['name' => 'Bongo Wooden Toys · বঙ্গ কাঠের খেলনা', 'opening_balance' => '1000.00']);
    $this->variant = ProductVariant::factory()->create();
});

it('owes nothing for an ordered PO until goods arrive, then owes what arrived', function (): void {
    $po = Kit::purchase([[$this->variant, 10, '100']], $this->supplier);
    $balances = app(SupplierBalances::class);

    expect($balances->for($this->supplier)['balance']->toDecimal())->toBe('1000.00');

    Kit::receive($po, [$po->items[0]->id => 4], freight: '40');
    $b = $balances->for($this->supplier);

    expect($b['opening']->toDecimal())->toBe('1000.00')
        ->and($b['received']->toDecimal())->toBe('440.00')
        ->and($b['paid']->toDecimal())->toBe('0.00')
        ->and($b['balance']->toDecimal())->toBe('1440.00');
});

it('records payments against the supplier and a PO, keeping the PO due amount', function (): void {
    $po = Kit::purchase([[$this->variant, 10, '100']], $this->supplier);
    Kit::receive($po, [$po->items[0]->id => 10]);

    pay($this->supplier, '600', $po->id);
    pay($this->supplier, '1000');

    $po->refresh();
    expect($po->paid_amount->toDecimal())->toBe('600.00')
        ->and($po->due_amount->toDecimal())->toBe('400.00')
        ->and(app(SupplierBalances::class)->for($this->supplier)['balance']->toDecimal())->toBe('400.00');

    // An advance beyond the balance is allowed and shows as negative.
    pay($this->supplier, '500');
    expect(app(SupplierBalances::class)->for($this->supplier)['balance']->toDecimal())->toBe('-100.00');
});

it('writes one supplier_payment ledger entry per payment: cash out, not revenue, on the Dhaka date', function (): void {
    $payment = pay($this->supplier, '2500.50', date: '2026-11-10');

    $row = DB::table('financial_transactions')->where('source_type', 'supplier_payment')->where('source_id', $payment->id)->sole();
    expect($row->type)->toBe('supplier_payment')
        ->and($row->direction)->toBe('out')
        ->and(bcadd((string) $row->amount, '0', 2))->toBe('2500.50')
        ->and(substr((string) $row->occurred_on, 0, 10))->toBe('2026-11-10')
        ->and((bool) $row->is_cash_movement)->toBeTrue()
        ->and((bool) $row->is_recognised_revenue)->toBeFalse();
});

it('writes nothing to the ledger on receipt, and keeps both invariants', function (): void {
    $po = Kit::purchase([[$this->variant, 5, '100']], $this->supplier);
    Kit::receive($po, [$po->items[0]->id => 5], freight: '25');

    expect(DB::table('financial_transactions')->count())->toBe(0)
        ->and(Kit::ledgerBalanced())->toBeTrue()
        ->and(app(FinanceInvariant::class)->drift())->toBe([]);
});

it('refuses a payment against another supplier\'s PO or a draft one', function (): void {
    $other = Supplier::factory()->create();
    $theirs = Kit::purchase([[$this->variant, 1, '100']], $other);
    $draft = Kit::purchase([[$this->variant, 1, '100']], $this->supplier, ordered: false);

    expect(fn () => pay($this->supplier, '10', $theirs->id))->toThrow(ValidationException::class)
        ->and(fn () => pay($this->supplier, '10', $draft->id))->toThrow(ValidationException::class)
        ->and(SupplierPayment::query()->count())->toBe(0)
        ->and(DB::table('financial_transactions')->count())->toBe(0);
});

it('never changes or deletes a recorded payment', function (): void {
    $payment = pay($this->supplier, '10');

    expect(fn () => $payment->update(['amount' => '20.00']))->toThrow(LogicException::class)
        ->and(fn () => $payment->delete())->toThrow(LogicException::class);
});
