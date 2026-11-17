<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Auth;

use App\Http\Requests\ApiFormRequest;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * `PUT /auth/profile` — `{ name, email, current_password? }`. Your own account only, so any
 * signed-in staff member may call it. Changing the email (the sign-in name) needs the current
 * password; renaming yourself does not.
 */
final class UpdateOwnProfileRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->getKey())],
            'current_password' => [
                Rule::requiredIf(fn (): bool => mb_strtolower(trim($this->string('email')->toString())) !== mb_strtolower($user->email)),
                'nullable',
                'string',
                'max:255',
                'current_password:web',
            ],
        ];
    }

    public function name(): string
    {
        return trim($this->string('name')->toString());
    }

    public function email(): string
    {
        return mb_strtolower(trim($this->string('email')->toString()));
    }
}
