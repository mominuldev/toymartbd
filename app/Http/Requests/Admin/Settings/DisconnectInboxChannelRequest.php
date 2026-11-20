<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Settings;

use App\Domain\Inbox\Enums\InboxChannel;
use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /settings/inbox-channels/{channel}/disconnect` — remove the saved Page / number and its
 * token; needs settings.manage_integrations. No body.
 */
final class DisconnectInboxChannelRequest extends ApiFormRequest
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
            throw new NotFoundHttpException('No such inbox channel.');
        }

        return $channel;
    }
}
