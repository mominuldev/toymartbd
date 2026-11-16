<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Staff;

use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;

/**
 * `PUT /staff/two-factor` — Staff & roles › Two-factor sign-in; needs staff.manage (owner only).
 * `required: true` makes everyone who can see money set up two-factor before using the admin.
 */
final class UpdateTwoFactorSettingRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::StaffManage->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'required' => ['required', 'boolean'],
        ];
    }

    public function isRequired(): bool
    {
        return $this->boolean('required');
    }
}
