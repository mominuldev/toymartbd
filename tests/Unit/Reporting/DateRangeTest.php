<?php

declare(strict_types=1);

use App\Domain\Finance\Reports\Enums\ReportGranularity;
use App\Domain\Finance\Reports\ReportPeriod;
use App\Domain\Reporting\Support\DateRange;
use Carbon\CarbonImmutable;

afterEach(fn () => CarbonImmutable::setTestNow());

/** 2026-11-13 is a Friday in Dhaka. 20:00 UTC on the 12th is already the 13th in Dhaka. */
function atDhakaFriday(): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-12 20:00:00', 'UTC'));
}

it('resolves each preset against the Dhaka date, not the UTC date', function (string $preset, string $from, string $to): void {
    atDhakaFriday();

    $range = DateRange::fromPreset($preset);

    expect($range->fromDate())->toBe($from)
        ->and($range->toDate())->toBe($to)
        ->and($range->preset)->toBe($preset);
})->with([
    'today' => ['today', '2026-11-13', '2026-11-13'],
    '7d' => ['7d', '2026-11-07', '2026-11-13'],
    '30d' => ['30d', '2026-10-15', '2026-11-13'],
    'this_month' => ['this_month', '2026-11-01', '2026-11-13'],
    'last_month' => ['last_month', '2026-10-01', '2026-10-31'],
]);

it('defaults to the last 7 days and infers custom from explicit dates', function (): void {
    atDhakaFriday();

    expect(DateRange::fromPreset(null)->toArray())->toBe(['preset' => '7d', 'from' => '2026-11-07', 'to' => '2026-11-13', 'days' => 7])
        ->and(DateRange::fromPreset(null, '2026-01-01', '2026-01-31')->preset)->toBe('custom');
});

it('rejects an unknown preset, a half custom range, dates with a non-custom preset, and a reversed range', function (?string $preset, ?string $from, ?string $to): void {
    expect(fn () => DateRange::fromPreset($preset, $from, $to))->toThrow(InvalidArgumentException::class);
})->with([
    'unknown' => ['90d', null, null],
    'custom without to' => ['custom', '2026-01-01', null],
    'preset with dates' => ['7d', '2026-01-01', '2026-01-07'],
    'reversed' => ['custom', '2026-02-01', '2026-01-01'],
    'not a date' => ['custom', '2026-02-30', '2026-03-01'],
]);

it('compares a rolling range with the same number of days immediately before it', function (): void {
    atDhakaFriday();

    expect(DateRange::fromPreset('7d')->previous()->toArray())->toBe(['preset' => 'custom', 'from' => '2026-10-31', 'to' => '2026-11-06', 'days' => 7])
        ->and(DateRange::fromPreset('today')->previous()->fromDate())->toBe('2026-11-12')
        ->and(DateRange::custom('2026-03-01', '2026-03-10')->previous()->toArray())->toBe(['preset' => 'custom', 'from' => '2026-02-19', 'to' => '2026-02-28', 'days' => 10]);
});

it('compares month presets with the same days of the previous month, clipped to its length', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-31 06:00:00', 'UTC'));

    // 1–31 March month-to-date compares with 1–28 Feb (2026 is not a leap year).
    expect(DateRange::fromPreset('this_month')->previous()->toArray())->toBe(['preset' => 'custom', 'from' => '2026-02-01', 'to' => '2026-02-28', 'days' => 28])
        // last_month (Feb) compares with the whole of January.
        ->and(DateRange::fromPreset('last_month')->previous()->toArray())->toBe(['preset' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31', 'days' => 31]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-15 06:00:00', 'UTC'));

    // last_month across a year boundary.
    expect(DateRange::fromPreset('last_month')->toArray())->toBe(['preset' => 'last_month', 'from' => '2025-12-01', 'to' => '2025-12-31', 'days' => 31])
        ->and(DateRange::fromPreset('last_month')->previous()->fromDate())->toBe('2025-11-01');
});

it('round-trips with the finance ReportPeriod and shares its bucketing', function (): void {
    $period = ReportPeriod::of('2026-11-01', '2026-11-30');
    $range = DateRange::fromPeriod($period);

    expect($range->period())->toBe($period)
        ->and($range->utcBounds()[0]->toIso8601String())->toBe('2026-10-31T18:00:00+00:00')
        ->and($range->utcBounds()[1]->toIso8601String())->toBe('2026-11-30T18:00:00+00:00')
        ->and($range->buckets(ReportGranularity::Week))->toBe($period->buckets(ReportGranularity::Week));
});

it('buckets weeks Saturday to Friday and clips the edge weeks', function (): void {
    // 2026-11-01 is a Sunday; 2026-11-07 a Saturday; 2026-11-30 a Monday.
    $weeks = DateRange::custom('2026-11-01', '2026-11-30')->buckets(ReportGranularity::Week);

    expect(array_column($weeks, 'key'))->toBe(['2026-11-01', '2026-11-07', '2026-11-14', '2026-11-21', '2026-11-28'])
        ->and($weeks[0])->toBe(['key' => '2026-11-01', 'from' => '2026-11-01', 'to' => '2026-11-06', 'days' => 6])
        ->and($weeks[4])->toBe(['key' => '2026-11-28', 'from' => '2026-11-28', 'to' => '2026-11-30', 'days' => 3])
        ->and(DateRange::custom('2026-11-01', '2026-11-30')->bucketOf('2026-11-13', ReportGranularity::Week))->toBe('2026-11-07')
        ->and(DateRange::custom('2026-11-01', '2026-11-30')->bucketOf('2026-11-14', ReportGranularity::Week))->toBe('2026-11-14');
});

it('builds a whole calendar month', function (): void {
    expect(DateRange::month(2028, 2)->toArray())->toBe(['preset' => 'custom', 'from' => '2028-02-01', 'to' => '2028-02-29', 'days' => 29]);
});

it('knows whether it includes today', function (): void {
    atDhakaFriday();

    expect(DateRange::fromPreset('this_month')->includesToday())->toBeTrue()
        ->and(DateRange::fromPreset('last_month')->includesToday())->toBeFalse();
});
