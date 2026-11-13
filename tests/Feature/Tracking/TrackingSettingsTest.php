<?php

declare(strict_types=1);

use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Tracking\Actions\UpdateTrackingSettings;
use App\Domain\Tracking\DTOs\TrackingSettingsData;
use App\Domain\Tracking\Enums\PurchaseTrigger;
use App\Domain\Tracking\Services\TrackingSettings;
use App\Models\AuditSensitive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * TrackingSettings persistence (stored like MessagingSettings) and the save that revalidates
 * the storefront's `tracking-config` tag.
 */

function trackingSettingsData(array $overrides = []): TrackingSettingsData
{
    return new TrackingSettingsData(...$overrides + [
        'serverSideEnabled' => true,
        'ga4Enabled' => true,
        'ga4MeasurementId' => 'G-ABC123XYZ',
        'metaEnabled' => true,
        'metaPixelId' => '123456789012345',
        'metaTestEventCode' => null,
        'gtmContainerId' => 'GTM-ABCD123',
        'purchaseTrigger' => PurchaseTrigger::OrderDelivered,
        'consentMode' => 'notice',
        'consentPolicyVersion' => '2026-11',
        'ga4ApiSecret' => 'ga4-api-secret-0123456789',
        'metaAccessToken' => 'EAAB-meta-token-0123456789',
    ]);
}

it('saves secrets encrypted, audits without values, and revalidates tracking-config after commit', function (): void {
    Queue::fake();

    app(UpdateTrackingSettings::class)->handle(trackingSettingsData(), null);

    $settings = app(TrackingSettings::class);
    expect($settings->ga4ApiSecret())->toBe('ga4-api-secret-0123456789')
        ->and($settings->metaAccessToken())->toBe('EAAB-meta-token-0123456789')
        ->and($settings->purchaseTrigger())->toBe(PurchaseTrigger::OrderDelivered)
        ->and($settings->consentMode())->toBe('notice')
        ->and($settings->serverSideEnabled())->toBeTrue()
        ->and($settings->secretHint(TrackingSettings::GA4_API_SECRET))->toBe('••••6789');

    $raw = DB::table('settings')->whereIn('key', TrackingSettings::SECRET_KEYS)->pluck('value')->implode(' ');
    expect($raw)->not->toContain('ga4-api-secret')->not->toContain('EAAB-meta-token');

    $audit = AuditSensitive::query()->sole();
    expect(json_encode([$audit->before, $audit->after]))->not->toContain('0123456789')
        ->and($audit->after)->toMatchArray(['scope' => 'tracking', 'ga4_api_secret' => 'changed', 'meta_access_token' => 'changed']);

    Queue::assertPushed(RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['tracking-config']);

    // The admin view never carries a secret.
    expect(json_encode($settings->adminView()))->not->toContain('0123456789')
        ->and($settings->adminView()['ga4_api_secret_set'])->toBeTrue();
});

it('keeps a secret on a blank save, clears it on request, and revalidates nothing when nothing changed', function (): void {
    app(UpdateTrackingSettings::class)->handle(trackingSettingsData(), null);
    Queue::fake();

    app(UpdateTrackingSettings::class)->handle(trackingSettingsData(['ga4ApiSecret' => null, 'metaAccessToken' => '']), null);
    expect(app(TrackingSettings::class)->ga4ApiSecret())->toBe('ga4-api-secret-0123456789');
    Queue::assertNothingPushed();

    app(UpdateTrackingSettings::class)->handle(trackingSettingsData(['ga4ApiSecret' => null, 'metaAccessToken' => null, 'clearGa4ApiSecret' => true]), null);
    expect(app(TrackingSettings::class)->ga4ApiSecret())->toBeNull();
    Queue::assertPushed(RevalidateStorefrontTags::class);
});
