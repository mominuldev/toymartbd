<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Cart;

use App\Support\PhoneNumber;
use Closure;

/**
 * `PUT /cart/contact` `{phone, marketing_consent, locale?}` (P7 abandoned-cart recovery).
 * `marketing_consent` is the shopper's explicit opt-in to one reminder about this cart.
 */
final class SetCartContactRequest extends CartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! PhoneNumber::isValid($value)) {
                    $fail((string) __('checkout.phone.invalid'));
                }
            }],
            'marketing_consent' => ['required', 'boolean'],
            'locale' => ['sometimes', 'in:en,bn'],
        ];
    }

    public function phone(): PhoneNumber
    {
        return PhoneNumber::parse($this->string('phone')->toString());
    }
}
