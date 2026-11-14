<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Domain\Inbox\Actions\UpdateInboxChannelSettings;
use App\Domain\Inbox\Queries\InboxChannelCredentialsView;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Settings\UpdateInboxChannelSettingsRequest;
use App\Http\Resources\Admin\InboxChannelSettingsResource;
use App\Models\Enums\Permission;

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
}
