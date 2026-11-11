<?php

declare(strict_types=1);

use App\Support\PhoneNumber;

it('accepts every live operator prefix 013–019', function (string $prefix): void {
    expect(PhoneNumber::isValid($prefix.'12345678'))->toBeTrue();
})->with(['013', '014', '015', '016', '017', '018', '019']);

it('rejects 010, 011, 012, the 06x IoT series and landlines', function (string $number): void {
    expect(PhoneNumber::isValid($number))->toBeFalse();
})->with(['01012345678', '01112345678', '01212345678', '06012345678', '0291234567']);

it('rejects wrong length and non-numeric input', function (string $number): void {
    expect(PhoneNumber::isValid($number))->toBeFalse();
})->with(['0171234567', '017123456789', '01712abc678', '', '+8801712345']);

it('normalises every input form to the same number', function (string $input): void {
    $phone = PhoneNumber::parse($input);

    expect($phone->e164())->toBe('+8801712345678')
        ->and($phone->national())->toBe('01712345678');
})->with(['01712345678', '+8801712345678', '8801712345678', '+880 1712-345678', '017 1234 5678', '(017) 12.34.56.78', '০১৭১২৩৪৫৬৭৮']);

it('masks for staff views and exposes last digits for tracking', function (): void {
    $phone = PhoneNumber::parse('01712345678');

    expect($phone->masked())->toBe('017****5678')
        ->and($phone->lastDigits())->toBe('5678')
        ->and($phone->equals(PhoneNumber::parse('+8801712345678')))->toBeTrue();
});

it('never echoes the rejected number in its error', function (): void {
    try {
        PhoneNumber::parse('01112345678');
        $this->fail('expected an exception');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->not->toContain('12345678');
    }
});
