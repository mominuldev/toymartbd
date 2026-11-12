<?php

declare(strict_types=1);

use App\Domain\Cart\DTOs\PriceBreakdown;
use App\Domain\Marketing\Services\Allocator;
use App\Support\Money;

/*
 * The arithmetic the pricing pipeline rests on: discounts split across lines without
 * losing or inventing a poisha, and a breakdown that does not sum refuses to exist.
 */

it('spreads a discount proportionally and exactly', function (): void {
    $shares = Allocator::spread(Money::of('100.00'), [1 => Money::of('333.33'), 2 => Money::of('333.33'), 3 => Money::of('333.34')]);

    expect(array_map(fn (Money $m) => $m->toDecimal(), $shares))->toBe([1 => '33.33', 2 => '33.33', 3 => '33.34'])
        ->and(Allocator::sum($shares)->toDecimal())->toBe('100.00');
});

it('never gives a line more than its amount', function (): void {
    $shares = Allocator::spread(Money::of('500.00'), [7 => Money::of('120.00'), 9 => Money::of('80.00')]);

    expect(array_map(fn (Money $m) => $m->toDecimal(), $shares))->toBe([7 => '120.00', 9 => '80.00']);
});

it('ignores zero lines and caps a per-line map', function (): void {
    expect(Allocator::spread(Money::of('10'), [1 => Money::zero()]))->toBe([])
        ->and(Allocator::sum(Allocator::cap([1 => Money::of('150'), 2 => Money::of('50')], Money::of('100')))->toDecimal())->toBe('100.00')
        ->and(Allocator::cap([1 => Money::of('50')], null)[1]->toDecimal())->toBe('50.00');
});

it('refuses a breakdown whose rows do not sum to the total', function (): void {
    new PriceBreakdown([], [], [], null, null, Money::zero(), Money::zero(), Money::zero(), Money::of('60.00'), Money::of('59.99'));
})->throws(LogicException::class);

it('accepts an empty cart with delivery', function (): void {
    $b = new PriceBreakdown([], [], [], null, null, Money::zero(), Money::zero(), Money::zero(), Money::of('60.00'), Money::of('60.00'));

    expect($b->isCheckoutReady())->toBeFalse()->and(count($b->itemised()))->toBe(2);
});
