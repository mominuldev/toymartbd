<?php

declare(strict_types=1);

use App\Support\StorefrontUrl;

/*
 * Bangla first (owner decision 2026-11-25, rule 35): Bangla at the root, English under /en.
 */

it('serves Bangla at the root with no prefix', function (): void {
    expect(StorefrontUrl::DEFAULT_LOCALE)->toBe('bn')
        ->and(StorefrontUrl::prefix('bn'))->toBe('')
        ->and(StorefrontUrl::path('bn', '/products/lego-city'))->toBe('/products/lego-city')
        ->and(StorefrontUrl::path('bn', '/'))->toBe('/')
        ->and(StorefrontUrl::path('bn', ''))->toBe('/')
        ->and(StorefrontUrl::path('bn', '/track?order=TMB-26-00001'))->toBe('/track?order=TMB-26-00001');
});

it('serves English under /en', function (): void {
    expect(StorefrontUrl::prefix('en'))->toBe('/en')
        ->and(StorefrontUrl::path('en', '/products/lego-city'))->toBe('/en/products/lego-city')
        ->and(StorefrontUrl::path('en', '/'))->toBe('/en')
        ->and(StorefrontUrl::path('en', ''))->toBe('/en')
        ->and(StorefrontUrl::path('en', '/?ref=sms'))->toBe('/en?ref=sms')
        ->and(StorefrontUrl::path('en', '/products/x#reviews'))->toBe('/en/products/x#reviews');
});

it('never builds the retired /bn prefix, and treats an unknown locale as the root locale', function (): void {
    foreach (['bn', 'en', 'xx', ''] as $locale) {
        expect(StorefrontUrl::path($locale, '/cart'))->not->toStartWith('/bn');
    }
    expect(StorefrontUrl::path('xx', '/cart'))->toBe('/cart');
});

it('builds absolute URLs on an origin, with or without a trailing slash', function (): void {
    expect(StorefrontUrl::url('https://shop.test', 'bn', '/'))->toBe('https://shop.test')
        ->and(StorefrontUrl::url('https://shop.test/', 'en', '/'))->toBe('https://shop.test/en')
        ->and(StorefrontUrl::url('https://shop.test/', 'bn', '/cart/restore/abc'))->toBe('https://shop.test/cart/restore/abc')
        ->and(StorefrontUrl::url('https://shop.test', 'en', '/cart/restore/abc'))->toBe('https://shop.test/en/cart/restore/abc')
        ->and(StorefrontUrl::url('shop.test', 'bn', '/track'))->toBe('shop.test/track');
});
