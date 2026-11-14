<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Staff;

use App\Http\Requests\ApiFormRequest;
use App\Models\User;
use Illuminate\Validation\Rules\Password;

/** `POST /staff/{user}/password` — `{ password }`, a new temporary password; needs staff.manage. */
final class ResetStaffPasswordRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $staff = $this->route('user');

        return $staff instanceof User && ($this->user()?->can('update', $staff) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:'.StoreStaffRequest::PASSWORD_MIN, 'max:128', Password::min(StoreStaffRequest::PASSWORD_MIN)],
        ];
    }

    public function password(): string
    {
        return $this->string('password')->toString();
    }
}
