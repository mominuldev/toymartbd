<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Account;

use App\Http\Requests\Storefront\Cart\CartRequest;
use App\Support\PhoneNumber;

/**
 * `POST /storefront/auth/register/verify` (owner decision 2026-11-20) — the `register` code plus
 * the new account's name and password. An optional `X-Cart-Token` names the guest cart to merge.
 */
final class CompleteRegistrationRequest extends CartRequest
{
    use NormalisesOtpCode;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32', PhoneRule::make()],
            /** The 6-digit code from the SMS. */
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
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

    public function name(): string
    {
        return $this->string('name')->squish()->toString();
    }

    public function password(): string
    {
        return (string) $this->input('password');
    }
}
