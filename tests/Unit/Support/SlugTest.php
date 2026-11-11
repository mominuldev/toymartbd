<?php

declare(strict_types=1);

use App\Support\Slug;

it('transliterates a Bangla name into a readable ASCII slug', function (): void {
    $slug = Slug::from('খেলনা গাড়ি');

    expect($slug)->toBe('khelna-gadi')->toMatch('/^[a-z0-9-]+$/');
});

it('serves one slug for both locales, preferring the English name', function (): void {
    expect(Slug::forNames('LEGO City Fire Truck', 'লেগো সিটি ফায়ার ট্রাক'))->toBe('lego-city-fire-truck')
        ->and(Slug::forNames(null, 'খেলনা গাড়ি'))->toBe('khelna-gadi')
        ->and(Slug::forNames('  ', 'খেলনা গাড়ি'))->toBe('khelna-gadi');
});

it('suffixes collisions', function (): void {
    $taken = ['lego-city', 'lego-city-2'];

    expect(Slug::unique('lego-city', fn (string $c): bool => in_array($c, $taken, true)))->toBe('lego-city-3')
        ->and(Slug::unique('puzzle', fn (string $c): bool => false))->toBe('puzzle');
});

it('caps length and refuses an empty result', function (): void {
    expect(strlen(Slug::from(str_repeat('toy ', 60))))->toBeLessThanOrEqual(120)
        ->and(fn () => Slug::from('🧸🎈'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Slug::forNames(null, null))->toThrow(InvalidArgumentException::class);
});
