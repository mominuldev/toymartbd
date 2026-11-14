<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Staff;

use App\Domain\Staff\DTOs\NewStaffData;
use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Role;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;

/**
 * `POST /staff` — `{ name, email, role, password }`; needs staff.manage. The password is
 * a temporary one the owner gives the new person in person or by phone, never in the
 * same message as the email (RUNBOOK §10). Email is stored lower-case.
 */
final class StoreStaffRequest extends ApiFormRequest
{
    public const int PASSWORD_MIN = 12;

    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', 'string', new Enum(Role::class)],
            'password' => ['required', 'string', 'min:'.self::PASSWORD_MIN, 'max:128', Password::min(self::PASSWORD_MIN)],
        ];
    }

    public function toData(): NewStaffData
    {
        return new NewStaffData(
            name: $this->string('name')->toString(),
            email: $this->string('email')->toString(),
            role: Role::from($this->string('role')->toString()),
            password: $this->string('password')->toString(),
        );
    }
}
