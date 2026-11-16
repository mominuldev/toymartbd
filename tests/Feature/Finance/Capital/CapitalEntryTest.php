<?php

declare(strict_types=1);

use App\Domain\Finance\Exceptions\LedgerViolation;
use App\Domain\Finance\Models\CapitalEntry;
use App\Domain\Finance\Models\FinancialTransaction;
use App\Domain\Finance\Reports\PnlLayout;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Finance\Capital\CapitalKit;
use Tests\Feature\Finance\FinanceKit;

beforeEach(function (): void {
    $this->user = FinanceKit::actingAs(Role::Accountant);
    $this->holder = CapitalKit::holder();
    $this->today = now('Asia/Dhaka')->toDateString();
});

/** Σ in − Σ out over cash movements: the cash position the ledger knows. */
function ledgerNetCash(): string
{
    $in = (string) DB::table('financial_transactions')->where('is_cash_movement', true)->where('direction', 'in')->sum('amount');
    $out = (string) DB::table('financial_transactions')->where('is_cash_movement', true)->where('direction', 'out')->sum('amount');

    return number_format((float) $in - (float) $out, 2, '.', '');
}

it('books a contribution as owner_capital_in: cash in, never revenue', function (): void {
    $response = CapitalKit::record(CapitalKit::body($this->holder, ['occurred_on' => '2026-11-10']))
        ->assertCreated()
        ->assertJsonPath('kind', 'contribution')
        ->assertJsonPath('amount', '50000.00')
        ->assertJsonPath('net_effect', '50000.00')
        ->assertJsonPath('occurred_on', '2026-11-10')
        ->assertJsonPath('method', 'bank_transfer')
        ->assertJsonPath('ledger_type', 'owner_capital_in')
        ->assertJsonPath('holder.name', 'Mominul Islam')
        ->assertJsonPath('recorded_by.id', $this->user->id)
        ->assertJsonPath('is_reversal', false)
        ->assertJsonPath('reversed_by_id', null)
        ->assertJsonPath('can.reverse', true);

    $rows = FinancialTransaction::query()->get();
    expect($rows)->toHaveCount(1);
    $row = $rows->first();
    expect($row->type->value)->toBe('owner_capital_in')
        ->and($row->direction->value)->toBe('in')
        ->and($row->amount->toDecimal())->toBe('50000.00')
        ->and($row->occurred_on->toDateString())->toBe('2026-11-10')
        ->and($row->source_type)->toBe('capital_entry')
        ->and($row->source_id)->toBe($response->json('id'))
        ->and($row->is_cash_movement)->toBeTrue()
        ->and($row->is_recognised_revenue)->toBeFalse();
});

it('books a drawing as owner_drawing: cash out, never an expense', function (): void {
    CapitalKit::record(CapitalKit::body($this->holder, ['kind' => 'drawing', 'amount' => '12000.50', 'method' => 'bkash']))
        ->assertCreated()
        ->assertJsonPath('ledger_type', 'owner_drawing')
        ->assertJsonPath('net_effect', '-12000.50');

    $row = FinancialTransaction::query()->sole();
    expect($row->type->value)->toBe('owner_drawing')
        ->and($row->direction->value)->toBe('out')
        ->and($row->amount->toDecimal())->toBe('12000.50')
        ->and($row->is_cash_movement)->toBeTrue()
        ->and($row->is_recognised_revenue)->toBeFalse();
});

it('keeps both capital types off the P&L layout', function (): void {
    expect(PnlLayout::pnlTypeValues())->not->toContain('owner_capital_in')
        ->and(PnlLayout::pnlTypeValues())->not->toContain('owner_drawing');
});

it('reverses an entry with its exact mirror, once only', function (): void {
    $id = CapitalKit::record(CapitalKit::body($this->holder, ['occurred_on' => '2026-11-01']))->json('id');

    $reversal = CapitalKit::reverse($id, 'Typed 50,000 instead of 5,000')
        ->assertCreated()
        ->assertJsonPath('is_reversal', true)
        ->assertJsonPath('reversal_of_id', $id)
        ->assertJsonPath('kind', 'contribution')
        ->assertJsonPath('amount', '50000.00')
        ->assertJsonPath('net_effect', '-50000.00')
        ->assertJsonPath('occurred_on', $this->today)
        ->assertJsonPath('note', 'Typed 50,000 instead of 5,000')
        ->assertJsonPath('can.reverse', false);

    $mirror = FinancialTransaction::query()->where('source_type', 'capital_reversal')->sole();
    expect($mirror->type->value)->toBe('owner_capital_in')
        ->and($mirror->direction->value)->toBe('out')
        ->and($mirror->amount->toDecimal())->toBe('50000.00')
        ->and($mirror->source_id)->toBe($reversal->json('id'))
        ->and($mirror->occurred_on->toDateString())->toBe($this->today)
        ->and($mirror->is_cash_movement)->toBeTrue()
        ->and($mirror->is_recognised_revenue)->toBeFalse()
        // The original stays on its own day, untouched (rule 8).
        ->and(FinancialTransaction::query()->where('source_type', 'capital_entry')->sole()->occurred_on->toDateString())->toBe('2026-11-01');

    // A second reversal, and a reversal of the reversal, are refused; nothing more is written.
    CapitalKit::reverse($id)->assertStatus(409)->assertJsonPath('type', 'https://toymartbd.com/problems/conflict');
    CapitalKit::reverse($reversal->json('id'))->assertStatus(409);
    expect(FinancialTransaction::query()->count())->toBe(2)
        ->and(CapitalEntry::query()->count())->toBe(2)
        ->and(ledgerNetCash())->toBe('0.00');

    $this->getJson(CapitalKit::URL.'/entries?sort=occurred_on')->assertOk()
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.reversed_by_id', $reversal->json('id'))
        ->assertJsonPath('data.0.can.reverse', false);
});

it('mirrors a drawing reversal as cash back in', function (): void {
    $id = CapitalKit::record(CapitalKit::body($this->holder, ['kind' => 'drawing', 'amount' => '3000.00']))->json('id');
    CapitalKit::reverse($id)->assertCreated()->assertJsonPath('net_effect', '3000.00');

    $mirror = FinancialTransaction::query()->where('source_type', 'capital_reversal')->sole();
    expect($mirror->type->value)->toBe('owner_drawing')->and($mirror->direction->value)->toBe('in');
});

it('requires a reason to reverse', function (): void {
    $id = CapitalKit::record(CapitalKit::body($this->holder))->json('id');
    $this->postJson(CapitalKit::URL."/entries/{$id}/reverse", [], ['Idempotency-Key' => CapitalKit::nextKey()])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'reason');
    expect(CapitalEntry::query()->count())->toBe(1);
});

it('leaves every P&L figure unchanged, while cash moves by exactly the amounts', function (): void {
    FinanceKit::deliver(FinanceKit::shippedOrder());
    $pnlUrl = "/api/v1/admin/finance/reports/pnl?filter[from]={$this->today}&filter[to]={$this->today}&compare=none";
    $strip = static fn (array $json): array => array_diff_key($json, array_flip(['generated_at', 'cash']));

    $before = $strip($this->getJson($pnlUrl)->assertOk()->json());
    $cashBefore = ledgerNetCash();
    $cashBlockBefore = $this->getJson($pnlUrl)->json('cash.net');

    $in = CapitalKit::record(CapitalKit::body($this->holder, ['amount' => '80000.00']))->assertCreated()->json('id');
    CapitalKit::record(CapitalKit::body($this->holder, ['kind' => 'drawing', 'amount' => '15000.25']))->assertCreated();
    CapitalKit::reverse($in)->assertCreated();
    CapitalKit::record(CapitalKit::body($this->holder, ['amount' => '20000.00']))->assertCreated();

    // Net cash: + 80,000 − 15,000.25 − 80,000 + 20,000 = + 4,999.75.
    $after = $this->getJson($pnlUrl)->assertOk();
    expect($strip($after->json()))->toEqual($before)
        ->and(bcsub(ledgerNetCash(), $cashBefore, 2))->toBe('4999.75')
        ->and(bcsub((string) $after->json('cash.net'), (string) $cashBlockBefore, 2))->toBe('4999.75');

    FinanceKit::assertFinanceInvariant();
    expect(Artisan::call('toymart:finance:verify'))->toBe(0);
});

it('rolls capital into its own daily summary columns and into cash, never into recognised in/out', function (): void {
    FinanceKit::deliver(FinanceKit::shippedOrder());
    Artisan::call('toymart:finance:rollup', ['--from' => $this->today, '--to' => $this->today]);
    $before = DB::table('daily_financial_summaries')->where('summary_date', $this->today)->first();

    $in = CapitalKit::record(CapitalKit::body($this->holder, ['amount' => '1000.00']))->json('id');
    CapitalKit::record(CapitalKit::body($this->holder, ['amount' => '500.00']));
    CapitalKit::record(CapitalKit::body($this->holder, ['kind' => 'drawing', 'amount' => '300.00']));
    CapitalKit::reverse($in);

    Artisan::call('toymart:finance:rollup', ['--from' => $this->today, '--to' => $this->today]);
    $row = DB::table('daily_financial_summaries')->where('summary_date', $this->today)->first();
    $money = static fn (mixed $v): string => number_format((float) $v, 2, '.', '');

    // owner_capital_in in its natural direction: 1,000 + 500 − 1,000 (reversal) = 500.
    expect($money($row->owner_capital_in))->toBe('500.00')
        ->and($money($row->owner_drawing))->toBe('300.00')
        ->and($money($row->cash_in))->toBe(bcadd($money($before->cash_in), '1500.00', 2))
        ->and($money($row->cash_out))->toBe(bcadd($money($before->cash_out), '1300.00', 2))
        ->and($money($row->net_cash))->toBe(bcadd($money($before->net_cash), '200.00', 2))
        ->and($money($row->recognised_in))->toBe($money($before->recognised_in))
        ->and($money($row->recognised_out))->toBe($money($before->recognised_out))
        ->and($money($row->net_profit))->toBe($money($before->net_profit));
});

it('validates the amount, the Dhaka date, the kind, the method and the holder', function (array $overrides, string $field): void {
    CapitalKit::record(CapitalKit::body($this->holder, $overrides))
        ->assertUnprocessable()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/validation-error')
        ->assertJsonPath('errors.0.field', $field);
    expect(CapitalEntry::query()->count())->toBe(0)->and(FinancialTransaction::query()->count())->toBe(0);
})->with([
    'zero amount' => [['amount' => '0.00'], 'amount'],
    'negative amount' => [['amount' => '-5.00'], 'amount'],
    'three decimals' => [['amount' => '10.555'], 'amount'],
    'not a number' => [['amount' => 'lots'], 'amount'],
    'tomorrow in Dhaka' => [['occurred_on' => now('Asia/Dhaka')->addDay()->toDateString()], 'occurred_on'],
    'not a date' => [['occurred_on' => '2026-02-30'], 'occurred_on'],
    'unknown kind' => [['kind' => 'loan'], 'kind'],
    'unknown method' => [['method' => 'crypto'], 'method'],
    'unknown holder' => [['holder_id' => 999999], 'holder_id'],
]);

it('refuses a new entry for an inactive holder, in English and Bangla', function (): void {
    $gone = CapitalKit::holder('Former Partner', active: false);

    CapitalKit::record(CapitalKit::body($gone))->assertUnprocessable()
        ->assertJsonPath('errors.0.field', 'holder_id')
        ->assertJsonPath('errors.0.message', 'This holder is inactive. Reactivate them before recording money in or out.');
    CapitalKit::record(CapitalKit::body($gone), headers: ['Accept-Language' => 'bn'])->assertUnprocessable()
        ->assertJsonPath('errors.0.message', 'এই অংশীদার নিষ্ক্রিয়। টাকা জমা বা উত্তোলন লেখার আগে তাঁকে আবার সক্রিয় করুন।');
    expect(CapitalEntry::query()->count())->toBe(0);
});

it('still reverses an entry after its holder was deactivated', function (): void {
    $id = CapitalKit::record(CapitalKit::body($this->holder))->json('id');
    $this->holder->update(['is_active' => false]);

    CapitalKit::reverse($id)->assertCreated();
});

it('says a double reversal is refused in Bangla', function (): void {
    $id = CapitalKit::record(CapitalKit::body($this->holder))->json('id');
    CapitalKit::reverse($id)->assertCreated();

    CapitalKit::reverse($id, headers: ['Accept-Language' => 'bn'])
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('detail', 'এই এন্ট্রিটি আগেই বাতিল (রিভার্স) করা হয়েছে। একটি এন্ট্রি একবারই রিভার্স করা যায়।');
});

it('replays a double submit with the same Idempotency-Key and writes once', function (): void {
    $body = CapitalKit::body($this->holder);
    $first = CapitalKit::record($body, 'capital-same-key-0001')->assertCreated();
    $second = CapitalKit::record($body, 'capital-same-key-0001')->assertCreated();

    expect($second->json('id'))->toBe($first->json('id'))
        ->and(CapitalEntry::query()->count())->toBe(1)
        ->and(FinancialTransaction::query()->count())->toBe(1);

    $reversed = CapitalKit::reverse($first->json('id'), key: 'capital-reverse-key-0001')->assertCreated();
    CapitalKit::reverse($first->json('id'), key: 'capital-reverse-key-0001')->assertCreated()->assertJsonPath('id', $reversed->json('id'));
    expect(FinancialTransaction::query()->count())->toBe(2);
});

it('requires an Idempotency-Key on both money-moving writes', function (): void {
    $this->postJson(CapitalKit::URL.'/entries', CapitalKit::body($this->holder))
        ->assertStatus(400)->assertJsonPath('type', 'https://toymartbd.com/problems/idempotency-key-missing');
    $id = CapitalKit::record(CapitalKit::body($this->holder))->json('id');
    $this->postJson(CapitalKit::URL."/entries/{$id}/reverse", ['reason' => 'Wrong amount'])
        ->assertStatus(400)->assertJsonPath('type', 'https://toymartbd.com/problems/idempotency-key-missing');
});

it('audits recording and reversing', function (): void {
    $id = CapitalKit::record(CapitalKit::body($this->holder, ['kind' => 'drawing', 'amount' => '700.00']))->json('id');
    CapitalKit::reverse($id, 'Was a supplier payment');

    $recorded = AuditSensitive::query()->where('action', AuditAction::CapitalEntryRecorded->value)->sole();
    $reversed = AuditSensitive::query()->where('action', AuditAction::CapitalEntryReversed->value)->sole();
    expect($recorded->user_id)->toBe($this->user->id)
        ->and($recorded->model_id)->toBe($id)
        ->and($recorded->after)->toMatchArray(['kind' => 'drawing', 'amount' => '700.00', 'holder_id' => $this->holder->id])
        ->and($reversed->after)->toMatchArray(['reversed' => true, 'reason' => 'Was a supplier payment'])
        ->and(AuditAction::CapitalEntryRecorded->area())->toBe('finance');
});

it('is insert-only: an entry can be neither updated nor deleted', function (): void {
    $entry = CapitalEntry::query()->findOrFail(CapitalKit::record(CapitalKit::body($this->holder))->json('id'));

    expect(fn () => $entry->forceFill(['amount' => '1.00'])->save())->toThrow(LedgerViolation::class)
        ->and(fn () => $entry->delete())->toThrow(LedgerViolation::class)
        ->and(CapitalEntry::query()->value('amount'))->toEqual('50000.00');
});

it('summarises put in, taken out and net per holder, reversals netted', function (): void {
    $partner = CapitalKit::holder('Rahima Akter');
    $a = CapitalKit::record(CapitalKit::body($this->holder, ['amount' => '100000.00', 'occurred_on' => '2026-11-01']))->json('id');
    CapitalKit::record(CapitalKit::body($this->holder, ['kind' => 'drawing', 'amount' => '25000.00', 'occurred_on' => '2026-11-05']));
    CapitalKit::record(CapitalKit::body($partner, ['amount' => '40000.00', 'occurred_on' => '2026-11-03']));
    $wrong = CapitalKit::record(CapitalKit::body($partner, ['amount' => '9999.00', 'occurred_on' => '2026-11-04']))->json('id');
    CapitalKit::reverse($wrong);

    $this->getJson(CapitalKit::URL)->assertOk()
        ->assertJsonPath('totals.put_in', '140000.00')
        ->assertJsonPath('totals.taken_out', '25000.00')
        ->assertJsonPath('totals.net', '115000.00')
        ->assertJsonPath('totals.entries_count', 5)
        ->assertJsonPath('holders.0.holder.name', 'Mominul Islam')
        ->assertJsonPath('holders.0.put_in', '100000.00')
        ->assertJsonPath('holders.0.taken_out', '25000.00')
        ->assertJsonPath('holders.0.net', '75000.00')
        ->assertJsonPath('holders.0.last_entry.occurred_on', '2026-11-05')
        ->assertJsonPath('holders.0.last_entry.kind', 'drawing')
        ->assertJsonPath('holders.1.holder.name', 'Rahima Akter')
        ->assertJsonPath('holders.1.put_in', '40000.00')
        ->assertJsonPath('holders.1.last_entry.is_reversal', true);

    $this->getJson(CapitalKit::URL.'?filter[kind]=drawing')->assertOk()
        ->assertJsonPath('totals.put_in', '0.00')->assertJsonPath('totals.taken_out', '25000.00');
    $this->getJson(CapitalKit::URL."?filter[holder_id]={$this->holder->id}&filter[from]=2026-11-02&filter[to]=2026-11-30")->assertOk()
        ->assertJsonCount(1, 'holders')
        ->assertJsonPath('from', '2026-11-02')
        ->assertJsonPath('totals.net', '-25000.00');

    $this->getJson(CapitalKit::URL.'/entries?filter[kind]=contribution&filter[holder_id]='.$this->holder->id)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a);
    $this->getJson(CapitalKit::URL.'/entries?sort=-amount')->assertOk()->assertJsonPath('data.0.amount', '100000.00');
});

it('lists an empty overview with every holder at zero', function (): void {
    $this->getJson(CapitalKit::URL)->assertOk()
        ->assertJsonPath('holders.0.put_in', '0.00')
        ->assertJsonPath('holders.0.last_entry', null)
        ->assertJsonPath('totals.net', '0.00');
});

it('answers an unlisted filter or sort, or a bad value, with a 400', function (string $query): void {
    $this->getJson($query)->assertStatus(400)->assertJsonPath('type', 'https://toymartbd.com/problems/bad-request');
})->with([
    'overview: unknown filter' => [CapitalKit::URL.'?filter[amount]=1'],
    'overview: any sort' => [CapitalKit::URL.'?sort=amount'],
    'overview: bad kind' => [CapitalKit::URL.'?filter[kind]=loan'],
    'overview: two kinds' => [CapitalKit::URL.'?filter[kind]=contribution,drawing'],
    'overview: bad date' => [CapitalKit::URL.'?filter[from]=2026-13-01'],
    'overview: to before from' => [CapitalKit::URL.'?filter[from]=2026-11-10&filter[to]=2026-11-01'],
    'entries: unknown filter' => [CapitalKit::URL.'/entries?filter[method]=cash'],
    'entries: unknown sort' => [CapitalKit::URL.'/entries?sort=holder'],
    'entries: bad holder id' => [CapitalKit::URL.'/entries?filter[holder_id]=abc'],
    'holders: unknown filter' => [CapitalKit::URL.'/holders?filter[name]=x'],
    'holders: bad active' => [CapitalKit::URL.'/holders?filter[active]=maybe'],
]);
