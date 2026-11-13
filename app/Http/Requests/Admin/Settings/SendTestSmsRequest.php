<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Settings;

use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use App\Support\PhoneNumber;

/**
 * `POST /settings/messaging/test` — one test SMS to a Bangladeshi mobile (rule 30:
 * 013–019 only), in English or Bangla; needs settings.manage_integrations.
 */
final class SendTestSmsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsManageIntegrations->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^(?:\+?880|0)1[3-9]\d{8}$/'],
            'locale' => ['required', 'string', 'in:en,bn'],
        ];
    }

    public function phone(): PhoneNumber
    {
        return PhoneNumber::parse($this->string('phone')->toString());
    }

    public function locale(): string
    {
        return $this->string('locale')->toString();
    }
}
