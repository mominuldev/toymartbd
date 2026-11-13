<?php

declare(strict_types=1);

use App\Domain\Finance\Reports\ReportMoney;
use App\Support\Money;

// Phase 9 (docs/audits/PERFORMANCE.md P-04): Money::normalise and ReportMoney::of skip the bcmath
// rounding for a value already in canonical two-decimal form. The fast path must return exactly
// what the rounding would have — these pin the edges where the two could differ.

it('keeps canonical values and canonicalises everything else exactly as before', function (string $in, string $out): void {
    expect(Money::of($in)->toDecimal())->toBe($out)
        ->and(ReportMoney::of($in)->toDecimal())->toBe($out);
})->with([
    ['1234.50', '1234.50'],
    ['-1234.50', '-1234.50'],
    ['0.00', '0.00'],
    ['-0.00', '0.00'],          // negative zero is not canonical
    ['007.50', '7.50'],         // leading zeros are not canonical
    ['-0.01', '-0.01'],
    ['999999999999.99', '999999999999.99'],
    ['12.5', '12.50'],
    ['12', '12.00'],
]);

it('still rejects an amount beyond DECIMAL(14,2) on the fast path', function (): void {
    Money::of('1000000000000.00');
})->throws(InvalidArgumentException::class);

it('sums like the bcmath reference over random amounts', function (): void {
    mt_srand(9);
    $sum = Money::zero();
    $reference = '0.00';
    for ($i = 0; $i < 5000; $i++) {
        $poisha = mt_rand(-5_000_000, 5_000_000);
        $text = ($poisha < 0 ? '-' : '').intdiv(abs($poisha), 100).'.'.str_pad((string) (abs($poisha) % 100), 2, '0', STR_PAD_LEFT);
        $amount = ReportMoney::of($text);
        expect($amount->toPoisha())->toBe($poisha);
        $sum = $sum->plus($amount);
        $reference = bcadd($reference, $text, 2);
    }
    $canonical = bccomp($reference, '0', 2) === 0 ? '0.00' : $reference;
    expect($sum->toDecimal())->toBe($canonical);
});

it('still rejects a sum beyond DECIMAL(14,2) and never returns negative zero from a difference', function (): void {
    expect(Money::of('5.25')->minus(Money::of('5.25'))->toDecimal())->toBe('0.00')
        ->and(Money::of('-999999999999.99')->plus(Money::of('0.01'))->toDecimal())->toBe('-999999999999.98');
    Money::of('999999999999.99')->plus(Money::of('0.01'));
})->throws(InvalidArgumentException::class);
