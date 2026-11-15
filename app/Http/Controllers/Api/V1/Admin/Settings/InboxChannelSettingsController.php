<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Domain\Inbox\Actions\TestInboxChannelConnection;
use App\Domain\Inbox\Actions\UpdateInboxChannelSettings;
use App\Domain\Inbox\Queries\InboxChannelCredentialsView;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Settings\TestInboxChannelConnectionRequest;
use App\Http\Requests\Admin\Settings\UpdateInboxChannelSettingsRequest;
use App\Http\Resources\Admin\InboxChannelSettingsResource;
use App\Http\Resources\Admin\InboxChannelTestResultResource;
use App\Models\Enums\Permission;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

/**
 * Admin → Integrations → Messaging, inbox channels (WhatsApp Cloud API, Messenger): the Meta
 * credentials entered in the admin, encrypted in `settings`, env as the fallback. Both
 * endpoints need settings.manage_integrations — even reading, because the card is about
 * credentials. Secrets are never returned. Every channel stays UNVERIFIED.
 */
final class InboxChannelSettingsController extends ApiController
{
    /** `GET /settings/inbox-channels` — driver, ids, secrets as set/source/last-4 hint, webhook URLs, readiness. */
    public function show(InboxChannelCredentialsView $view): InboxChannelSettingsResource
    {
        $this->authorize(Permission::SettingsManageIntegrations->value);

        return new InboxChannelSettingsResource($view->get());
    }

    /** `PUT /settings/inbox-channels` — blank secret = keep; `clear_*` = remove. Audited (`credential_changed`, scope `inbox_{channel}`). */
    public function update(UpdateInboxChannelSettingsRequest $request, UpdateInboxChannelSettings $update, InboxChannelCredentialsView $view): InboxChannelSettingsResource
    {
        $userId = $request->user()?->getAuthIdentifier();
        $update->handle($request->toData(), is_int($userId) ? $userId : null);

        return new InboxChannelSettingsResource($view->get());
    }

    /**
     * `POST /settings/inbox-channels/{channel}/test` — one read-only Graph API call with the saved
     * credentials (WhatsApp: the phone number's display number, verified name, quality rating;
     * Messenger: the Page name). Stored as the channel's `last_check` and audited. A failed check
     * is a 200 with `ok: false`. Never marks the channel verified. Rate-limited.
     */
    #[OpenApiResponse(429, 'Too many tests (`too-many-requests`)')]
    public function test(TestInboxChannelConnectionRequest $request, TestInboxChannelConnection $test): InboxChannelTestResultResource
    {
        $userId = $request->user()?->getAuthIdentifier();
        $channel = $request->channel();

        return new InboxChannelTestResultResource(['channel' => $channel->value] + $test->handle($channel, is_int($userId) ? $userId : null));
    }
}
