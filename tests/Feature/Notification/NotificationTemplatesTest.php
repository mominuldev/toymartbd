<?php

declare(strict_types=1);

use App\Domain\Notification\Actions\SyncDefaultTemplates;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Support\TemplateCatalog;

/*
 * Default templates (P4-I18N-03): seeded EN + BN, segment counts measured and stored.
 */

it('seeds every catalog template with both locales and stored counts', function (): void {
    $expected = collect(TemplateCatalog::all())->sum(fn (array $d): int => count($d['channels']));

    expect(NotificationTemplate::query()->count())->toBe($expected);

    NotificationTemplate::query()->each(function (NotificationTemplate $t): void {
        expect($t->body_en)->not->toBe('')
            ->and($t->body_bn)->not->toBe('')
            ->and($t->chars_en)->toBeGreaterThan(0)
            ->and($t->chars_bn)->toBeGreaterThan(0);

        if ($t->channel === NotificationChannel::Sms) {
            expect($t->segments_en)->toBeGreaterThanOrEqual(1)->and($t->encoding_en)->toBe('gsm7')
                ->and($t->segments_bn)->toBeGreaterThanOrEqual(1)->and($t->encoding_bn)->toBe('ucs2');
        } else {
            expect($t->segments_en)->toBeNull()->and($t->segments_bn)->toBeNull();
        }
    });
});

it('records the Bangla order-placed SMS as two segments where English is one', function (): void {
    $sms = NotificationTemplate::for('order.placed', NotificationChannel::Sms);

    expect($sms->segments_en)->toBe(1)
        ->and($sms->segments_bn)->toBe(2)
        ->and($sms->chars_bn)->toBeGreaterThan(70);
});

it('keeps the OTP SMS at one segment in both languages', function (): void {
    $otp = NotificationTemplate::for('otp.login', NotificationChannel::Sms);

    expect($otp->segments_en)->toBe(1)->and($otp->segments_bn)->toBe(1);
});

it('never offers OTP over WhatsApp (rule 32b)', function (): void {
    expect(NotificationTemplate::for('otp.login', NotificationChannel::WhatsApp))->toBeNull()
        ->and(TemplateCatalog::supports('otp.login', NotificationChannel::WhatsApp))->toBeFalse();
});

it('syncs idempotently and never overwrites an edited template', function (): void {
    $sms = NotificationTemplate::for('order.placed', NotificationChannel::Sms);
    $sms->update(['body_en' => 'Edited {order_number}']);

    expect(app(SyncDefaultTemplates::class)->handle())->toBe(0)
        ->and($sms->refresh()->body_en)->toBe('Edited {order_number}');

    $sms->delete();
    expect(app(SyncDefaultTemplates::class)->handle())->toBe(1);
});
