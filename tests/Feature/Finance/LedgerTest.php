<?php

declare(strict_types=1);

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Finance\Exceptions\LedgerViolation;
use App\Domain\Finance\Models\FinancialTransaction;
use App\Domain\Ordering\Models\OrderFinancial;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Tests\Feature\Finance\FinanceKit;

function entry(LedgerEntryType $type = LedgerEntryType::OtherIncome, int $sourceId = 1, string $amount = '100.00', ?CarbonImmutable $on = null): LedgerEntry
{
    return new LedgerEntry(
        type: $type,
        direction: $type->naturalDirection(),
        amount: Money::of($amount),
        occurredOn: $on ?? CarbonImmutable::parse('2026-11-20', 'Asia/Dhaka'),
        sourceType: 'test',
        sourceId: $sourceId,
        isCashMovement: true,
        isRecognisedRevenue: false,
    );
}

it('defines every ledger type of DATABASE-DESIGN §3.7', function (): void {
    expect(LedgerEntryType::values())->toBe([
        'sale_revenue', 'cogs', 'discount_given', 'shipping_revenue', 'courier_charge', 'courier_cod_fee',
        'courier_return_charge', 'gateway_fee', 'packaging', 'marketing', 'salary', 'rent', 'utility', 'refund',
        'inventory_write_off', 'supplier_payment', 'cod_received', 'other_income', 'other_expense',
    ])->and(Direction::values())->toBe(['in', 'out']);
});

it('records entries and ignores a replay of the same source and type', function (): void {
    $ledger = app(Ledger::class);
    $ledger->record(entry(), entry(LedgerEntryType::OtherExpense));
    $ledger->record(entry(), entry(LedgerEntryType::OtherExpense), entry(sourceId: 2));

    expect(FinancialTransaction::query()->count())->toBe(3)
        ->and(FinancialTransaction::query()->where('source_id', 1)->where('type', 'other_income')->value('amount'))->toEqual('100.00');
});

it('refuses a negative amount and a missing source', function (): void {
    expect(fn () => entry(amount: '-1.00'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new LedgerEntry(LedgerEntryType::Rent, Direction::Out, Money::of('1'), CarbonImmutable::now(), '', 0, true, true))
        ->toThrow(InvalidArgumentException::class);
});

it('is append-only: an entry can be neither updated nor deleted', function (): void {
    app(Ledger::class)->record(entry());
    $row = FinancialTransaction::query()->firstOrFail();

    expect(fn () => $row->forceFill(['amount' => '1.00'])->save())->toThrow(LedgerViolation::class)
        ->and(fn () => $row->delete())->toThrow(LedgerViolation::class)
        ->and(FinancialTransaction::query()->value('amount'))->toEqual('100.00');
});

it('buckets an instant at 23:30 UTC on the next Dhaka date', function (): void {
    // 2026-11-20 23:30 UTC is 2026-11-21 05:30 in Dhaka (UTC+6).
    $instant = CarbonImmutable::parse('2026-11-20 23:30:00', 'UTC');
    app(Ledger::class)->record(entry(on: LedgerEntry::dhakaDateOf($instant)), entry(LedgerEntryType::Rent, on: $instant));

    expect(FinancialTransaction::query()->pluck('occurred_on')->map->toDateString()->all())
        ->toBe(['2026-11-21', '2026-11-21']);
});

it('keeps the order projection equal to the ledger when another domain posts to an order', function (): void {
    $order = FinanceKit::shippedOrder();
    FinanceKit::deliver($order);

    // A gateway fee posted later against the order itself (e.g. by the Payment listener).
    app(Ledger::class)->record(new LedgerEntry(
        LedgerEntryType::GatewayFee, Direction::Out, Money::of('25.50'), CarbonImmutable::now('Asia/Dhaka'),
        'order', $order->id, isCashMovement: true, isRecognisedRevenue: true,
    ));

    $f = OrderFinancial::query()->findOrFail($order->id);
    expect($f->gateway_fee->toDecimal())->toBe('25.50')
        ->and($f->net_profit->toDecimal())->toBe('444.50'); // 1100 − 700 + 70 − 25.50
    FinanceKit::assertFinanceInvariant();
});
