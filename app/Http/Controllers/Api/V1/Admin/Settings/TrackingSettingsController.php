<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Domain\Tracking\Actions\SendTestTrackingEvent;
use App\Domain\Tracking\Actions\UpdateTrackingSettings;
use App\Domain\Tracking\Services\TrackingSettings;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Settings\SendTestTrackingEventRequest;
use App\Http\Requests\Admin\Settings\UpdateTrackingSettingsRequest;
use App\Http\Resources\Admin\TrackingSettingsResource;
use App\Http\Resources\Admin\TrackingTestResultResource;
use App\Models\Enums\Permission;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

/**
 * Admin → Integrations → Tracking & pixels (PRD K-01..K-03, Figma B · 25 241:31086): GA4,
 * Meta Pixel + Conversions API and GTM ids, the purchase trigger and the consent mode,
 * stored encrypted in `settings` with env as the fallback. Every endpoint needs
 * settings.manage_integrations (super_admin and manager — owner decision 2026-11-13), even
 * reading, because the screen is about credentials. Secrets are never returned.
 */
final class TrackingSettingsController extends ApiController
{
    /** `GET /settings/tracking` — effective values; secrets only as set/source/last-4 hint. */
    public function show(TrackingSettings $settings): TrackingSettingsResource
    {
        $this->authorize(Permission::SettingsManageIntegrations->value);

        return new TrackingSettingsResource($settings->adminView());
    }

    /**
     * `PUT /settings/tracking` — blank secret = keep; `clear_*` = remove. Audited
     * (`credential_changed`, scope `tracking`); a change revalidates the storefront's
     * `tracking-config` cache tag after commit.
     */
    public function update(UpdateTrackingSettingsRequest $request, UpdateTrackingSettings $update, TrackingSettings $settings): TrackingSettingsResource
    {
        $userId = $request->user()?->getAuthIdentifier();
        $update->handle($request->toData(), is_int($userId) ? $userId : null);

        return new TrackingSettingsResource($settings->adminView());
    }

    /**
     * `POST /settings/tracking/test` — one synthetic purchase through the destination's
     * validation path (GA4 validation server / Meta test_event_code) with the SAVED
     * settings; never counts as a conversion and writes no event row. Rate-limited; no
     * transaction around the provider call (rule 4).
     */
    #[OpenApiResponse(429, 'Too many test events (`too-many-requests`)')]
    public function test(SendTestTrackingEventRequest $request, SendTestTrackingEvent $send): TrackingTestResultResource
    {
        $destination = $request->destination();

        return new TrackingTestResultResource(['destination' => $destination, 'result' => $send->handle($destination)]);
    }
}
