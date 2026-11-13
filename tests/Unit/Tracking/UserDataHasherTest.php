<?php

declare(strict_types=1);

use App\Domain\Tracking\Support\DecimalNumber;
use App\Domain\Tracking\Support\SecretScrubber;
use App\Domain\Tracking\Support\UserDataHasher as H;

/*
 * Hash vectors. The Meta vectors (john_smith@gmail.com, (650)555-1212, Mary, "us") are the
 * worked examples printed in Meta's customer-information-parameters doc; the Bangladeshi
 * ones were computed independently (python hashlib) for these normalised strings.
 * https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/customer-information-parameters
 */

it('reproduces Meta\'s own documented hash examples', function (): void {
    expect(H::metaEmail('john_smith@gmail.com'))->toBe('62a14e44f765419d10fea99367361a727c12365e2520f32218d505ed9aa0f62f')
        ->and(H::sha256('16505551212'))->toBe('e323ec626319ca94ee8bff2e4c87cf613be6ea19919ed1364124e16807ab3176')
        ->and(H::metaName('Mary'))->toBe('6915771be1c5aa0c886870b6951b03d7eafc121fea0e80a5ea83beb7c449f4ec')
        ->and(H::metaCountry('US'))->toBe('79adb2a2fce5c6ba215fe5f27f532d4e7edbac4b6a5e09e1ef3a08084a904621');
});

it('normalises a Bangladeshi phone to 880… digits before hashing, whatever the input form', function (string $input): void {
    expect(H::phoneDigits($input))->toBe('8801712345678')
        ->and(H::metaPhone($input))->toBe('c327520f85b0c6058fed05dfc0a63d8755f325b6ea1b550824f4a8e380d75de1')
        // GA4 wants E.164 WITH the '+' before hashing.
        ->and(H::ga4Phone($input))->toBe('650037f77977759c37e1a76c2e101bc1957b7f52f9567ecaa65a35586e05c08c');
})->with(['01712345678', '+8801712345678', '8801712345678', '+880 1712-345678']);

it('trims and lower-cases a mixed-case email', function (): void {
    expect(H::metaEmail('  Mixed.Case@Example.COM '))->toBe('7a126a993c9ece5663288f1e48a453a6b4b12656af38d84103cb6beb8a5862b9')
        ->and(H::ga4Email('  Mixed.Case@Example.COM '))->toBe('7a126a993c9ece5663288f1e48a453a6b4b12656af38d84103cb6beb8a5862b9');
});

it('strips dots before @ for gmail on GA4 only', function (): void {
    expect(H::ga4Email('John.Smith@gmail.com'))->toBe(hash('sha256', 'johnsmith@gmail.com'))
        ->and(H::metaEmail('John.Smith@gmail.com'))->toBe(hash('sha256', 'john.smith@gmail.com'));
});

it('normalises city with no spaces or punctuation for Meta, and keeps Bangla script', function (): void {
    expect(H::metaCity("Cox's Bazar"))->toBe('43dea41fc4e7f595cead44b14c4bfb1033a9af49026171bfc5b03a66b290ad37')
        ->and(H::metaCity('Dhaka'))->toBe('de90643718108ec9a93cc6905f2b976297505a8abeac37b747132877fb32fb8d')
        ->and(H::metaName('রহিম।'))->toBe('935191c07439504afd0dcda32cac9d6fac60531598805a25788b6cbf855f420a')
        ->and(H::metaCountry('bd'))->toBe('5e657ff6158d3e2a6d23e2a523917a2305acee9423365e268695c4b7b8919f4c');
});

it('returns null for empty input rather than hashing an empty string', function (): void {
    expect(H::metaEmail('  '))->toBeNull()
        ->and(H::metaPhone(null))->toBeNull()
        ->and(H::metaName('...'))->toBeNull()
        ->and(H::ga4Name('123'))->toBeNull();
});

it('turns a money decimal string into an exact JSON number', function (string $in, string $json): void {
    expect(json_encode(DecimalNumber::toJsonNumber($in)))->toBe($json);
})->with([
    ['1999.90', '1999.9'],
    ['0.10', '0.1'],
    ['120.00', '120'],
    ['1500', '1500'],
    ['999999999999.99', '999999999999.99'],
    ['0.30', '0.3'],
]);

it('rejects a non-decimal or unrepresentable amount', function (string $in): void {
    expect(fn () => DecimalNumber::toJsonNumber($in))->toThrow(InvalidArgumentException::class);
})->with(['abc', '1e5', '12345678901234567.12']);

it('scrubs secrets and secret query values from any string', function (): void {
    $text = 'POST https://x/mp/collect?measurement_id=G-1&api_secret=s3cr3t {"access_token":"EAAtok"} access_token=EAAtok';

    expect(SecretScrubber::scrub($text, ['s3cr3t', 'EAAtok']))
        ->not->toContain('s3cr3t')->not->toContain('EAAtok');
    expect(strlen(SecretScrubber::excerpt(str_repeat('a', 5000), [])))->toBe(2048);
});
