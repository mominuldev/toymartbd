<?php

declare(strict_types=1);

use App\Domain\Notification\Support\SmsSegments;
use App\Domain\Notification\Support\TemplateCatalog;
use App\Domain\Notification\Support\TemplateRenderer;

/*
 * SMS segment arithmetic (P4-I18N-03, rule 39): GSM-7 160/153, UCS-2 (Bangla) 70/67.
 */

it('counts GSM-7 at 160 for one segment and 153 per segment after that', function (): void {
    expect(SmsSegments::count(str_repeat('a', 160)))->toBe(1)
        ->and(SmsSegments::count(str_repeat('a', 161)))->toBe(2)
        ->and(SmsSegments::count(str_repeat('a', 306)))->toBe(2)
        ->and(SmsSegments::count(str_repeat('a', 307)))->toBe(3)
        ->and(SmsSegments::encoding('Order TMB-26-00482, BDT 2450'))->toBe('gsm7');
});

it('counts Bangla as UCS-2 at 70 per segment, 67 when multipart — not 160', function (): void {
    $bn = 'ক';

    expect(SmsSegments::encoding($bn))->toBe('ucs2')
        ->and(SmsSegments::count(str_repeat($bn, 70)))->toBe(1)
        ->and(SmsSegments::count(str_repeat($bn, 71)))->toBe(2)
        ->and(SmsSegments::count(str_repeat($bn, 134)))->toBe(2)
        ->and(SmsSegments::count(str_repeat($bn, 135)))->toBe(3)
        // 100 Bangla characters: 2 segments, where the same length in Latin is 1.
        ->and(SmsSegments::count(str_repeat($bn, 100)))->toBe(2)
        ->and(SmsSegments::count(str_repeat('a', 100)))->toBe(1);
});

it('switches the whole message to UCS-2 for one non-GSM character such as ৳ or an en-dash', function (): void {
    $latin = str_repeat('a', 100);

    expect(SmsSegments::count($latin))->toBe(1)
        ->and(SmsSegments::count($latin.'৳'))->toBe(2)
        ->and(SmsSegments::count($latin.'–'))->toBe(2);
});

it('charges two septets for GSM-7 extension characters', function (): void {
    expect(SmsSegments::units('{}'))->toBe(4)
        ->and(SmsSegments::count(str_repeat('a', 159).'€'))->toBe(2);
});

it('keeps every English SMS template GSM-7 clean when rendered', function (): void {
    foreach (TemplateCatalog::all() as $key => $definition) {
        $sms = $definition['channels']['sms'] ?? null;
        if ($sms === null) {
            continue;
        }
        $rendered = TemplateRenderer::render($sms['body_en'], TemplateCatalog::sampleValues('en'), $definition['variables']);

        expect(SmsSegments::isGsm7($rendered))->toBeTrue("{$key} English SMS leaves GSM-7: {$rendered}");
    }
});

it('holds the Bangla OTP inside one 70-character segment, forever', function (): void {
    $definition = TemplateCatalog::find('otp.login');
    $rendered = TemplateRenderer::render($definition['channels']['sms']['body_bn'], TemplateCatalog::sampleValues('bn'), $definition['variables']);

    expect(mb_strlen($rendered))->toBeLessThanOrEqual(70)
        ->and(SmsSegments::count($rendered))->toBe(1);
});
