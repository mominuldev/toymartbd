<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Settings;

use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;

/**
 * `POST /settings/inbox-channels/messenger/login/finish` — the `code` and `state` Facebook added
 * to the redirect back to Inbox › Channels › Facebook Page; needs settings.manage_integrations.
 */
final class FinishMessengerLoginRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsManageIntegrations->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The `code` query parameter Facebook sent back (valid for a few minutes). */
            'code' => ['required', 'string', 'max:2048'],
            /** The `state` query parameter: the one-time value from `…/messenger/login`. */
            'state' => ['required', 'string', 'size:48', 'alpha_num'],
        ];
    }
}
