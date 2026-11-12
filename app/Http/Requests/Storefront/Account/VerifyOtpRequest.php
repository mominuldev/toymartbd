<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Account;

use App\Http\Requests\Storefront\Cart\CartRequest;
use App\Support\PhoneNumber;

/**
 * `POST /storefront/auth/otp/verify` (P4-STO-11). An optional `X-Cart-Token` names the
 * guest cart to merge into the customer's cart on sign-in.
 */
final class VerifyOtpRequest extends CartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32', PhoneRule::make()],
            /** The 6-digit code from the SMS. */
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['code.regex' => (string) __('account.otp.format')];
    }

    protected function prepareForValidation(): void
    {
        // Bengali digits and stray spaces from an SMS autofill are the same code.
        $code = $this->input('code');
        if (is_string($code)) {
            $code = str_replace(['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'], ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $code);
            $this->merge(['code' => preg_replace('/\s+/u', '', $code)]);
        }
    }

    public function phone(): PhoneNumber
    {
        return PhoneNumber::parse($this->string('phone')->toString());
    }

    public function code(): string
    {
        return $this->string('code')->toString();
    }
}
