<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Domain\Notification\Actions\SendTestSms;
use App\Domain\Notification\Actions\UpdateMessagingSettings;
use App\Domain\Notification\Services\MessagingSettings;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Settings\SendTestSmsRequest;
use App\Http\Requests\Admin\Settings\UpdateMessagingSettingsRequest;
use App\Http\Resources\Admin\MessagingSettingsResource;
use App\Http\Resources\Admin\SmsTestResultResource;
use App\Models\Enums\Permission;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

/**
 * Admin → Integrations → Messaging (owner request 2026-11-13): SMS provider credentials
 * entered in the admin, stored encrypted in `settings`, env as the fallback. Every
 * endpoint needs settings.manage_integrations (super_admin and manager — owner decision 2026-11-13) — even
 * reading, because the screen is about credentials. Secrets are never returned.
 */
final class MessagingSettingsController extends ApiController
{
    /** `GET /settings/messaging` — effective values; secrets only as set/source/last-4 hint. */
    public function show(MessagingSettings $settings): MessagingSettingsResource
    {
        $this->authorize(Permission::SettingsManageIntegrations->value);

        return new MessagingSettingsResource($settings->adminView());
    }

    /** `PUT /settings/messaging` — blank secret = keep; `clear_*` = remove. Audited (`credential_changed`). */
    public function update(UpdateMessagingSettingsRequest $request, UpdateMessagingSettings $update, MessagingSettings $settings): MessagingSettingsResource
    {
        $userId = $request->user()?->getAuthIdentifier();
        $update->handle($request->toData(), is_int($userId) ? $userId : null);

        return new MessagingSettingsResource($settings->adminView());
    }

    /**
     * `POST /settings/messaging/test` — sends ONE real SMS through the configured driver and
     * reports what the provider said. Rate-limited; no transaction around the provider call.
     */
    #[OpenApiResponse(429, 'Too many test sends (`too-many-requests`)')]
    public function test(SendTestSmsRequest $request, SendTestSms $send): SmsTestResultResource
    {
        $result = $send->handle($request->phone(), $request->locale());

        return new SmsTestResultResource(['driver' => $send->driver(), 'result' => $result]);
    }
}
