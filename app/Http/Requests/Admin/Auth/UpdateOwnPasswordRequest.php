<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Auth;

use App\Http\Requests\Admin\Staff\StoreStaffRequest;
use App\Http\Requests\ApiFormRequest;
use App\Models\User;
use Illuminate\Validation\Rules\Password;

/**
 * `PUT /auth/password` — `{ current_password, password, password_confirmation }`. Your own
 * password only; the same minimum as a staff password set by the owner.
 */
final class UpdateOwnPasswordRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:255', 'current_password:web'],
            'password' => ['required', 'string', 'min:'.StoreStaffRequest::PASSWORD_MIN, 'max:128', Password::min(StoreStaffRequest::PASSWORD_MIN), 'confirmed', 'different:current_password'],
        ];
    }

    public function password(): string
    {
        return $this->string('password')->toString();
    }
}
