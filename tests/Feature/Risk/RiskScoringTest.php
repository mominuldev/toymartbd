<?php

declare(strict_types=1);

use App\Domain\Ordering\Enums\CancellationReason;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Risk\DTOs\PhoneHistory;
use App\Domain\Risk\DTOs\RiskInput;
use App\Domain\Risk\Services\OrderRiskAssessor;
use App\Domain\Risk\Services\RiskScorer;
use App\Domain\Risk\Services\RiskSettings;
use App\Support\Money;
use App\Support\PhoneNumber;
use Tests\Feature\Risk\RiskKit;

/*
 * The weighted score (DATABASE-DESIGN §5.2): thresholds at ±1, breakdown = score, the
 * first-order compensating control (rule 33b), weights from the Risk rules settings.
 */

function riskOf(string $value, ?int $prior = 0, string $phone = '01712345678'): App\Domain\Risk\DTOs\RiskAssessment
{
    return app(OrderRiskAssessor::class)->assess(new RiskInput(PhoneNumber::parse($phone), Money::of($value), $prior));
}

function history(int $total = 0, int $delivered = 0, int $cancelled = 0, int $refused = 0, int $failed = 0, ?string $avg = null, int $recent = 0): PhoneHistory
{
    return new PhoneHistory(RiskKit::PHONE, null, $total, $delivered, $cancelled, $cancelled, 0, $refused, $failed, $avg, $recent, null);
}

it('buckets the score at each level threshold, ±1', function (int $score, string $level): void {
    expect(app(RiskSettings::class)->level($score))->toBe($level);
})->with([
    [39, 'low'], [40, 'medium'], [41, 'medium'],
    [69, 'medium'], [70, 'high'], [71, 'high'],
    [0, 'low'], [100, 'high'],
]);

it('holds on a high score at the high threshold, ±1 — and only holds', function (int $weight, bool $held): void {
    // A known phone (1 prior order) so only the score can hold it; first_order carries the weight.
    RiskKit::rules(['cancellation_rate' => $weight]);
    Order::factory()->status(OrderStatus::Cancelled)->create(['shipping_phone' => RiskKit::PHONE, 'cancel_reason' => CancellationReason::FakeOrder]);
    Order::factory()->status(OrderStatus::Cancelled)->create(['shipping_phone' => RiskKit::PHONE, 'cancel_reason' => CancellationReason::FakeOrder]);
    Order::factory()->status(OrderStatus::Cancelled)->create(['shipping_phone' => RiskKit::PHONE, 'cancel_reason' => CancellationReason::FakeOrder]);

    $risk = riskOf('500.00', null);

    expect($risk->score)->toBe($weight)
        ->and(in_array('high_risk_score', $risk->holdReasons, true))->toBe($held)
        ->and($risk->shouldHold())->toBe($held);
})->with([
    'one below high (69)' => [69, false],
    'at high (70)' => [70, true],
    'one above high (71)' => [71, true],
]);

it('reads the level thresholds from the saved rules, ±1', function (int $score, string $level): void {
    RiskKit::rules(['first_order' => $score], medium: 30, high: 60);

    expect(riskOf('100.00')->level)->toBe($level);
})->with([[29, 'low'], [30, 'medium'], [31, 'medium'], [59, 'medium'], [60, 'high'], [61, 'high']]);

it('holds every first order from an unknown phone at the threshold regardless of score (rule 33b), ±1', function (string $value, bool $held): void {
    RiskKit::rules([], threshold: '3000.00'); // every weight 0: the score cannot hold anything

    $risk = riskOf($value);

    expect($risk->score)->toBe(0)
        ->and(in_array('first_order_above_threshold', $risk->holdReasons, true))->toBe($held);
})->with([
    'one poisha below' => ['2999.99', false],
    'at the threshold' => ['3000.00', true],
    'one poisha above' => ['3000.01', true],
    'one taka below' => ['2999.00', false],
    'one taka above' => ['3001.00', true],
]);

it('does not apply the first-order rule to a phone that has ordered before', function (): void {
    Order::factory()->create(['shipping_phone' => RiskKit::PHONE]);

    expect(riskOf('90000.00', null)->holdReasons)->not->toContain('first_order_above_threshold');
});

it('sums the breakdown to the score, every factor at most its weight', function (PhoneHistory $h): void {
    $breakdown = app(RiskScorer::class)->score($h, new App\Domain\Risk\DTOs\OrderContext(Money::of('9000.00'), 'ঢাকা', false));

    expect(array_sum(array_column($breakdown->factors, 'contribution')))->toBe($breakdown->score);
    foreach ($breakdown->factors as $f) {
        expect($f['contribution'])->toBeLessThanOrEqual($f['weight'])->toBeGreaterThanOrEqual(0);
    }
})->with([
    'unknown phone' => [history()],
    'mixed history' => [history(total: 5, delivered: 2, cancelled: 2, refused: 1, failed: 1, avg: '1200.00', recent: 1)],
    'worst case' => [history(total: 9, delivered: 0, cancelled: 6, refused: 3, failed: 5, avg: null, recent: 3)],
]);

it('reaches 95 at most with the §5.2 default weights (they total 95)', function (): void {
    $worst = app(RiskScorer::class)->score(
        history(total: 9, delivered: 1, cancelled: 9, refused: 9, failed: 9, avg: '100.00', recent: 3),
        new App\Domain\Risk\DTOs\OrderContext(Money::of('9000.00'), 'x', false),
    );

    expect(array_sum(app(RiskSettings::class)->weights()))->toBe(95)
        ->and($worst->score)->toBeLessThanOrEqual(95);
});

it('weighs a cancellation rate by confidence (full weight from 3 prior orders)', function (): void {
    $scorer = app(RiskScorer::class);
    $one = collect($scorer->score(history(total: 1, cancelled: 1))->factors)->firstWhere('code', 'cancellation_rate');
    $three = collect($scorer->score(history(total: 3, cancelled: 3))->factors)->firstWhere('code', 'cancellation_rate');

    expect($one['contribution'])->toBe(8)   // 25 × 1/1 × 1/3, rounded
        ->and($three['contribution'])->toBe(25)
        ->and($three['value'])->toBe('3/3');
});

it('does not hold our own cancellations against the phone', function (): void {
    foreach ([CancellationReason::OutOfStock, CancellationReason::PricingError, CancellationReason::AddressUnserviceable] as $reason) {
        Order::factory()->status(OrderStatus::Cancelled)->create(['shipping_phone' => RiskKit::PHONE, 'cancel_reason' => $reason]);
    }

    $factor = collect(riskOf('100.00', null)->signals)->firstWhere('code', 'cancellation_rate');

    expect($factor)->toBeNull();
});
