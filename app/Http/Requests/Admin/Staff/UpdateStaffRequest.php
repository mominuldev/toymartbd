<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Staff;

use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Role;
use App\Models\User;
use Illuminate\Validation\Rules\Enum;

/**
 * `PATCH /staff/{user}` — `{ name?, role? }`; needs staff.manage. Email is not editable:
 * it is the sign-in name and appears in the audit trail. Add a new account instead.
 */
final class UpdateStaffRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $staff = $this->route('user');

        return $staff instanceof User && ($this->user()?->can('update', $staff) ?? false);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'role' => ['sometimes', 'required', 'string', new Enum(Role::class)],
        ];
    }

    public function name(): ?string
    {
        return $this->has('name') ? $this->string('name')->toString() : null;
    }

    public function role(): ?Role
    {
        return $this->has('role') ? Role::from($this->string('role')->toString()) : null;
    }
}
