<?php

declare(strict_types=1);

use App\Support\Money;

it('adds and subtracts exactly, with no float drift', function (): void {
    expect(Money::of('0.1')->plus(Money::of('0.2'))->toDecimal())->toBe('0.30')
        ->and(Money::of('2450')->minus(Money::of('2450.01'))->toDecimal())->toBe('-0.01')
        ->and(Money::of(70)->toDecimal())->toBe('70.00');
});

it('rounds half away from zero at the third decimal', function (string $in, string $out): void {
    expect(Money::of($in)->toDecimal())->toBe($out);
})->with([
    ['0.005', '0.01'],
    ['0.004', '0.00'],
    ['1.115', '1.12'],
    ['-0.005', '-0.01'],
    ['-0.004', '0.00'],
    ['12.344999', '12.34'],
]);

it('takes percentages and multiplies with rounding', function (): void {
    expect(Money::of('99.99')->percentage('12.5')->toDecimal())->toBe('12.50')
        ->and(Money::of('1250')->percentage(15)->toDecimal())->toBe('187.50')
        ->and(Money::of('33.33')->times(3)->toDecimal())->toBe('99.99')
        ->and(Money::of('10.00')->times('0.333')->toDecimal())->toBe('3.33');
});

it('allocates 100.00 across three lines without losing a poisha', function (): void {
    $parts = Money::of('100')->allocate(1, 1, 1);

    expect(array_map(fn (Money $m): string => $m->toDecimal(), $parts))->toBe(['33.34', '33.33', '33.33'])
        ->and(array_reduce($parts, fn (Money $c, Money $m): Money => $c->plus($m), Money::zero())->toDecimal())->toBe('100.00');
});

it('allocates by uneven ratios and negative amounts', function (): void {
    $uneven = Money::of('0.05')->allocate(3, 7);
    $negative = Money::of('-10.00')->allocate(1, 2);

    expect(array_map('strval', $uneven))->toBe(['0.02', '0.03'])
        ->and(array_map('strval', $negative))->toBe(['-3.33', '-6.67']);
});

it('rejects floats-as-strings it cannot read, bad ratios and overflow', function (): void {
    expect(fn () => Money::of('1e3'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of('12,450'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of('1000000000000'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of('5')->allocate(0, 0))->toThrow(InvalidArgumentException::class);
});

it('compares, converts to poisha and serialises as a string', function (): void {
    $a = Money::of('2450.50');

    expect($a->greaterThan(Money::of('2450.49')))->toBeTrue()
        ->and($a->equals(Money::of('2450.5')))->toBeTrue()
        ->and($a->toPoisha())->toBe(245050)
        ->and(Money::fromPoisha(-5)->toDecimal())->toBe('-0.05')
        ->and(json_encode(['total' => $a]))->toBe('{"total":"2450.50"}')
        ->and(Money::zero()->isZero())->toBeTrue()
        ->and($a->negate()->isNegative())->toBeTrue();
});
