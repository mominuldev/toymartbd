<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Settings;

use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;

/**
 * `POST /settings/inbox-channels/messenger/login` — start "Continue with Facebook"; needs
 * settings.manage_integrations. No body.
 */
final class StartMessengerLoginRequest extends ApiFormRequest
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
}
