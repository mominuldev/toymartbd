<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Account;

use Illuminate\Validation\Rules\Password;

/**
 * A storefront customer's new password (owner decision 2026-11-20): at least 8 characters
 * (Laravel `Password::min(8)`), at most 128, typed twice (`password_confirmation`).
 */
final class CustomerPasswordRule
{
    public const int MIN = 8;

    public const int MAX = 128;

    /** @return list<mixed> */
    public static function rules(): array
    {
        return ['required', 'string', 'max:'.self::MAX, Password::min(self::MIN), 'confirmed'];
    }
}
