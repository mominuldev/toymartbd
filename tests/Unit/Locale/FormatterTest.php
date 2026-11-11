<?php

declare(strict_types=1);

use App\Support\Locale\Formatter;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;

it('formats money with ৳, no space, three-digit grouping and decimals only when non-zero', function (Money|string|int $in, string $out): void {
    expect(Formatter::money($in, 'en'))->toBe($out)
        ->and(Formatter::money($in, 'bn'))->toBe($out);
})->with([
    ['2450', '৳2,450'],
    ['2450.00', '৳2,450'],
    ['2450.5', '৳2,450.50'],
    ['0.45', '৳0.45'],
    ['0', '৳0'],
    [1250, '৳1,250'],
    ['125000', '৳125,000'],
    ['1250000', '৳1,250,000'],
    ['999999999999.99', '৳999,999,999,999.99'],
    ['-450', "\u{2212}৳450"],
    [Money::of('6260.00'), '৳6,260'],
]);

it('rounds a retail price to whole taka, half away from zero', function (string $in, string $out): void {
    expect(Formatter::price($in))->toBe($out);
})->with([
    ['2450.49', '৳2,450'],
    ['2450.50', '৳2,451'],
    ['999.99', '৳1,000'],
    ['-450.50', "\u{2212}৳451"],
    ['-0.40', '৳0'],
]);

it('formats SMS money without the ৳ glyph', function (): void {
    expect(Formatter::smsMoney('2450.00'))->toBe('BDT 2450')
        ->and(Formatter::smsMoney(Money::of('0.45')))->toBe('BDT 0.45');
});

it('never uses lakh grouping or Bengali digits in bn', function (): void {
    $samples = [
        Formatter::money('1234567.89', 'bn'),
        Formatter::price('1234567', 'bn'),
        Formatter::number('1234567.891', 2, 'bn'),
        Formatter::quantity(1234567, 'bn'),
        Formatter::dateTime(CarbonImmutable::parse('2026-11-08 10:30:00', 'UTC'), 'bn'),
    ];

    foreach ($samples as $sample) {
        expect($sample)->not->toMatch('/[০-৯]/u')->not->toContain('12,34,567');
    }

    expect($samples[0])->toBe('৳1,234,567.89');
});

it('formats numbers and quantities', function (): void {
    expect(Formatter::number('1250000.5', 2))->toBe('1,250,000.50')
        ->and(Formatter::number('2.345', 2))->toBe('2.35')
        ->and(Formatter::number('-2.345', 2))->toBe('-2.35')
        ->and(Formatter::number('-0.001', 2))->toBe('0.00')
        ->and(Formatter::number(4))->toBe('4')
        ->and(Formatter::quantity(1200))->toBe('1,200');
});

it('rejects a non-numeric number', function (): void {
    Formatter::number('12abc');
})->throws(InvalidArgumentException::class);

it('formats dates in Dhaka time — 23:30 UTC is the next day', function (): void {
    $instant = CarbonImmutable::parse('2026-11-07 23:30:00', 'UTC');

    expect(Formatter::date($instant, 'en'))->toBe('8 Nov 2026')
        ->and(Formatter::date($instant, 'bn'))->toBe('8 নভেম্বর 2026')
        ->and(Formatter::time($instant, 'en'))->toBe('5:30 AM')
        ->and(Formatter::dateTime($instant, 'en'))->toBe('8 Nov 2026, 5:30 AM');
});

it('localises the day period in Bangla with Western digits', function (): void {
    $afternoon = CarbonImmutable::parse('2026-11-08 10:30:00', 'UTC'); // 16:30 Dhaka

    expect(Formatter::time($afternoon, 'en'))->toBe('4:30 PM')
        ->and(Formatter::time($afternoon, 'bn'))->toBe('বিকাল 4:30')
        ->and(Formatter::dateTime($afternoon, 'bn'))->toBe('8 নভেম্বর 2026, বিকাল 4:30');
});

it('uses the app locale when none is given and en for an unknown one', function (): void {
    $instant = CarbonImmutable::parse('2026-11-08 10:30:00', 'UTC');

    App::setLocale('bn');
    expect(Formatter::date($instant))->toBe('8 নভেম্বর 2026');

    expect(Formatter::date($instant, 'xx'))->toBe('8 Nov 2026');
});

it('honours config(app.display_timezone)', function (): void {
    config(['app.display_timezone' => 'UTC']);

    expect(Formatter::date(CarbonImmutable::parse('2026-11-07 23:30:00', 'UTC'), 'en'))->toBe('7 Nov 2026');
});

it('converts stray Bengali digits to Western', function (): void {
    expect(Formatter::westernDigits('১২,৫০,০০০'))->toBe('12,50,000');
});
