<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Account;

use App\Http\Requests\Storefront\Cart\CartRequest;
use App\Support\PhoneNumber;

/**
 * `POST /storefront/auth/password/reset` (owner decision 2026-11-20) — the `password_reset` code
 * and the new password. An optional `X-Cart-Token` names the guest cart to merge on sign-in.
 */
final class ResetPasswordRequest extends CartRequest
{
    use NormalisesOtpCode;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32', PhoneRule::make()],
            /** The 6-digit code from the SMS. */
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'password' => CustomerPasswordRule::rules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['code.regex' => (string) __('account.otp.format')];
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
