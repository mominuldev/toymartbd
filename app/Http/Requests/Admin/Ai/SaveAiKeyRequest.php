<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Ai;

use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;

/** `PUT /settings/ai/providers/{provider}` — connect a provider with its API key; needs settings.manage_integrations. */
final class SaveAiKeyRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsManageIntegrations->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The provider's secret API key. Checked with a real call before it is saved; never returned. */
            'api_key' => ['required', 'string', 'min:16', 'max:300', 'regex:/^\S+$/'],
        ];
    }

    public function apiKey(): string
    {
        return $this->string('api_key')->trim()->toString();
    }
}
