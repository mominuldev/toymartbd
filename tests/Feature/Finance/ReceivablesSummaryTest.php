<?php

declare(strict_types=1);

use App\Domain\Finance\Services\Receivables;
use App\Domain\Ordering\Models\Order;
use App\Support\DhakaDate;
use Illuminate\Support\Facades\DB;

// Phase 9 (docs/audits/PERFORMANCE.md P-06): the receivables summary reads the collectable amounts
// and the delivery dates in one ledger pass. Pins the aging buckets, the open count and the totals
// so the single-pass rewrite cannot drift from total() / forOrder().

function receivableLedger(int $orderId, string $type, string $direction, string $amount, string $on): void
{
    DB::table('financial_transactions')->insert([
        'type' => $type, 'direction' => $direction, 'amount' => $amount, 'occurred_on' => $on, 'source_type' => 'order',
        'source_id' => $orderId, 'is_cash_movement' => $type === 'cod_received', 'is_recognised_revenue' => $type !== 'cod_received',
        'created_at' => now(),
    ]);
}

it('ages each open COD order from its delivery date and agrees with total() and forOrder()', function (): void {
    $today = DhakaDate::todayDate();
    $fresh = Order::factory()->create(['payment_method' => 'cod']);
    $old = Order::factory()->create(['payment_method' => 'cod']);
    $paid = Order::factory()->create(['payment_method' => 'cod']);

    receivableLedger($fresh->id, 'sale_revenue', 'in', '1000.00', $today->subDays(3)->toDateString());
    receivableLedger($fresh->id, 'shipping_revenue', 'in', '70.00', $today->subDays(3)->toDateString());
    receivableLedger($old->id, 'sale_revenue', 'in', '2500.00', $today->subDays(40)->toDateString());
    receivableLedger($old->id, 'discount_given', 'out', '100.00', $today->subDays(40)->toDateString());
    receivableLedger($old->id, 'cod_received', 'in', '400.00', $today->subDays(20)->toDateString());
    receivableLedger($paid->id, 'sale_revenue', 'in', '900.00', $today->subDays(10)->toDateString());
    receivableLedger($paid->id, 'cod_received', 'in', '900.00', $today->subDays(5)->toDateString());

    $receivables = app(Receivables::class);
    $summary = $receivables->summary();

    expect($summary['recognised']->toDecimal())->toBe('4370.00')
        ->and($summary['received']->toDecimal())->toBe('1300.00')
        ->and($summary['outstanding']->toDecimal())->toBe('3070.00')
        ->and($summary['outstanding']->equals($receivables->total()))->toBeTrue()
        ->and($summary['open_orders'])->toBe(2)
        ->and($summary['aging']['0_7']->toDecimal())->toBe('1070.00')
        ->and($summary['aging']['8_15']->toDecimal())->toBe('0.00')
        ->and($summary['aging']['31_plus']->toDecimal())->toBe('2000.00')
        ->and($receivables->forOrder($old->id)->toDecimal())->toBe('2000.00')
        ->and($receivables->forOrder($paid->id)->toDecimal())->toBe('0.00');
});
