<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Account;

use App\Http\Requests\ApiFormRequest;
use App\Support\PhoneNumber;

/**
 * `POST /storefront/auth/otp` (P4-STO-11) — text a sign-in code. Called by the Next.js
 * server with its storefront token; the route's ability authorises it.
 */
final class RequestOtpRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** A Bangladeshi mobile, 013–019 (e.g. "01712345678" or "+8801712345678"). */
            'phone' => ['required', 'string', 'max:32', PhoneRule::make()],
        ];
    }

    public function phone(): PhoneNumber
    {
        return PhoneNumber::parse($this->string('phone')->toString());
    }
}
