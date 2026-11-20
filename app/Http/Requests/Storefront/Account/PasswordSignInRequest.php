<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Account;

use App\Http\Requests\Storefront\Cart\CartRequest;
use App\Support\PhoneNumber;

/**
 * `POST /storefront/auth/password` (owner decision 2026-11-20) — phone + password. An optional
 * `X-Cart-Token` names the guest cart to merge into the customer's cart on sign-in.
 */
final class PasswordSignInRequest extends CartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** A Bangladeshi mobile, 013–019. */
            'phone' => ['required', 'string', 'max:32', PhoneRule::make()],
            'password' => ['required', 'string', 'max:'.CustomerPasswordRule::MAX],
        ];
    }

    public function phone(): PhoneNumber
    {
        return PhoneNumber::parse($this->string('phone')->toString());
    }

    public function password(): string
    {
        return (string) $this->input('password');
    }
}
