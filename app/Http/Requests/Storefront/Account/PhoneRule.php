<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Account;

use App\Support\PhoneNumber;
use Closure;

/** Rule 30: 013–019 only, any accepted spelling; the input is never echoed back. */
final class PhoneRule
{
    public static function make(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! PhoneNumber::isValid($value)) {
                $fail((string) __('checkout.phone.invalid'));
            }
        };
    }
}
