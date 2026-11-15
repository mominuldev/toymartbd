<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Settings;

use App\Domain\Inbox\Enums\InboxChannel;
use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /settings/inbox-channels/{channel}/test` — one read-only Graph API call with the saved
 * WhatsApp / Messenger credentials; needs settings.manage_integrations. No body: the channel is
 * in the path (`whatsapp` | `messenger`, constrained on the route).
 */
final class TestInboxChannelConnectionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsManageIntegrations->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    public function channel(): InboxChannel
    {
        $channel = InboxChannel::tryFrom((string) $this->route('channel'));
        if (! in_array($channel, [InboxChannel::WhatsApp, InboxChannel::Messenger], true)) {
            throw new NotFoundHttpException('No such inbox channel to test.');
        }

        return $channel;
    }
}
