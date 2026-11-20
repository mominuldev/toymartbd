<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Account;

/**
 * `PUT /storefront/account/password` (owner decision 2026-11-20) — set the first password (after
 * an SMS sign-in) or change it. `current_password` is required by the Action only when the
 * customer already has one.
 */
final class UpdatePasswordRequest extends AccountRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Required when the customer already has a password. */
            'current_password' => ['nullable', 'string', 'max:'.CustomerPasswordRule::MAX],
            'password' => CustomerPasswordRule::rules(),
        ];
    }

    public function currentPassword(): ?string
    {
        $value = $this->input('current_password');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function password(): string
    {
        return (string) $this->input('password');
    }
}
