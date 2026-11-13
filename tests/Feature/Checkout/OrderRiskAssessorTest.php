<?php

declare(strict_types=1);

use App\Domain\Risk\DTOs\RiskInput;
use App\Domain\Risk\Services\OrderRiskAssessor;
use App\Support\Money;
use App\Support\PhoneNumber;

/*
 * The launch risk rule (rule 33b): our own history only; hold, never cancel.
 */

function assess(string $value, int $prior, bool $advance = false): App\Domain\Risk\DTOs\RiskAssessment
{
    return app(OrderRiskAssessor::class)->assess(new RiskInput(PhoneNumber::parse('01712345678'), Money::of($value), $prior, $advance));
}

beforeEach(fn () => config(['risk.first_order_hold_threshold' => '3000.00']));

it('holds a first order at or above the threshold', function (): void {
    expect(assess('3000.00', 0)->holdReasons)->toBe(['first_order_above_threshold'])
        ->and(assess('2999.99', 0)->shouldHold())->toBeFalse()
        ->and(assess('9000.00', 1)->shouldHold())->toBeFalse();
});

it('scores a first order low and records the signal', function (): void {
    $risk = assess('500.00', 0);

    expect($risk->score)->toBe(10)
        ->and($risk->level)->toBe('low')
        ->and($risk->signals)->toBe([['code' => 'first_order', 'weight' => 10, 'value' => '0', 'contribution' => 10]])
        ->and($risk->toFlags())->toHaveKeys(['signals', 'hold_reasons', 'computed_at']);
});

it('holds when the COD advance must be arranged', function (): void {
    expect(assess('500.00', 3, advance: true)->holdReasons)->toBe(['cod_advance_required']);
});

it('reads the threshold from config', function (): void {
    config(['risk.first_order_hold_threshold' => '500.00']);

    expect(assess('500.00', 0)->shouldHold())->toBeTrue();
});
