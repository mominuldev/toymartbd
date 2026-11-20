<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Settings;

use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;

/**
 * `POST /settings/inbox-channels/messenger/connect` — the Page chosen after "Continue with
 * Facebook"; needs settings.manage_integrations.
 */
final class ConnectMessengerPageRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsManageIntegrations->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The `session` from `…/messenger/login/finish`. */
            'session' => ['required', 'string', 'size:48', 'alpha_num'],
            /** One of the Page ids that call returned. */
            'page_id' => ['required', 'string', 'regex:/^\d{1,32}$/'],
        ];
    }
}
