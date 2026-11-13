<?php

declare(strict_types=1);

use App\Domain\Inventory\Actions\ReceivePurchasedStock;
use App\Domain\Purchasing\Services\LandedCostApportioner;
use App\Support\Money;

/*
 * Landed-cost apportionment (DATABASE-DESIGN §3.2): shares are exact to the poisha and
 * always sum to the total, whatever the weights.
 */

function apportionSum(array $shares): string
{
    return array_reduce($shares, static fn (Money $sum, Money $m): Money => $sum->plus($m), Money::zero())->toDecimal();
}

it('splits freight by line value', function (): void {
    // Figma 16 Purchase orders: ৳2,400 freight over goods of ৳8,940 / ৳17,040 / ৳18,600.
    $shares = LandedCostApportioner::apportion(Money::of('2400'), ['8940.00', '17040.00', '18600.00']);

    expect(array_map(static fn (Money $m): string => $m->toDecimal(), $shares))->toBe(['481.29', '917.36', '1001.35'])
        ->and(apportionSum($shares))->toBe('2400.00');
});

it('sums exactly to the poisha when the split does not divide evenly', function (string $total, array $weights): void {
    $shares = LandedCostApportioner::apportion(Money::of($total), $weights);

    expect(apportionSum($shares))->toBe(Money::of($total)->toDecimal())
        ->and($shares)->toHaveCount(count($weights));
    foreach ($shares as $share) {
        expect($share->isNegative())->toBeFalse();
    }
})->with([
    'thirds' => ['100', ['1', '1', '1']],
    'one poisha over seven' => ['0.01', ['3', '2', '2', '1', '1', '1', '1']],
    'awkward values' => ['1234.57', ['333.33', '333.33', '333.34', '0.01']],
    'large import' => ['999999999.99', ['123456789.12', '987654321.98', '1.00']],
    'many lines' => ['2400', array_fill(0, 37, '17.13')],
]);

it('gives the leftover poisha to the largest remainders, earliest first on a tie', function (): void {
    $shares = LandedCostApportioner::apportion(Money::of('100'), ['1', '1', '1']);

    expect(array_map(static fn (Money $m): string => $m->toDecimal(), $shares))->toBe(['33.34', '33.33', '33.33']);
});

it('is deterministic for the same input', function (): void {
    $a = LandedCostApportioner::apportion(Money::of('777.77'), ['10', '20', '30', '40']);
    $b = LandedCostApportioner::apportion(Money::of('777.77'), ['10', '20', '30', '40']);

    expect($a)->toEqual($b);
});

it('gives a zero-weight line nothing', function (): void {
    $shares = LandedCostApportioner::apportion(Money::of('50'), ['0', '100']);

    expect($shares[0]->toDecimal())->toBe('0.00')->and($shares[1]->toDecimal())->toBe('50.00');
});

it('returns zeros for a zero total and refuses all-zero weights for a non-zero one', function (): void {
    expect(apportionSum(LandedCostApportioner::apportion(Money::zero(), ['0', '0'])))->toBe('0.00');

    LandedCostApportioner::apportion(Money::of('10'), ['0', '0']);
})->throws(InvalidArgumentException::class);

it('refuses a negative weight', function (): void {
    LandedCostApportioner::apportion(Money::of('10'), ['-1', '2']);
})->throws(InvalidArgumentException::class);

it('steps the weighted average, and restarts it at the landed cost from zero or negative stock', function (): void {
    // (10 × 300 + 30 × 312) / 40 = 309
    expect(ReceivePurchasedStock::nextAverage(10, '300.0000', 30, '312.0000'))->toBe('309.0000')
        // rounds half-up at the 4th decimal: (1 × 1 + 2 × 2) / 3 = 1.66666…
        ->and(ReceivePurchasedStock::nextAverage(1, '1', 2, '2'))->toBe('1.6667')
        ->and(ReceivePurchasedStock::nextAverage(0, '999.0000', 5, '120.5000'))->toBe('120.5000')
        ->and(ReceivePurchasedStock::nextAverage(-3, '999.0000', 5, '120.5000'))->toBe('120.5000');
});
