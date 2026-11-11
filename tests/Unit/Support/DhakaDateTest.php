<?php

declare(strict_types=1);

use App\Support\DhakaDate;
use Carbon\CarbonImmutable;

it('buckets an order placed at 23:30 UTC into the next Dhaka day', function (): void {
    expect(DhakaDate::dayOf(CarbonImmutable::parse('2026-11-10 23:30:00', 'UTC')))->toBe('2026-11-11')
        ->and(DhakaDate::dayOf(CarbonImmutable::parse('2026-11-10 17:59:59', 'UTC')))->toBe('2026-11-10')
        ->and(DhakaDate::dayOf(CarbonImmutable::parse('2026-11-10 18:00:00', 'UTC')))->toBe('2026-11-11');
});

it('gives the UTC half-open bounds of a Dhaka day', function (): void {
    [$start, $end] = DhakaDate::utcBounds('2026-11-11');

    expect($start->toIso8601String())->toBe('2026-11-10T18:00:00+00:00')
        ->and($end->toIso8601String())->toBe('2026-11-11T18:00:00+00:00');
});

it('reports today in Dhaka, not UTC', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 20:00:00', 'UTC'));

    expect(DhakaDate::today())->toBe('2026-11-11');

    CarbonImmutable::setTestNow();
});

it('rejects a malformed date', function (): void {
    expect(fn () => DhakaDate::utcBounds('2026-02-30'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DhakaDate::utcBounds('11/11/2026'))->toThrow(InvalidArgumentException::class);
});
