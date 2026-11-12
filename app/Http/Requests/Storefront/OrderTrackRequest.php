<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront;

use App\Domain\Ordering\Services\OrderNumberGenerator;
use App\Http\Requests\ApiFormRequest;
use App\Support\PhoneNumber;
use Closure;

/**
 * `POST /storefront/orders/track` (P4-STO-10, rule 22): order number **plus** phone
 * digits — the last 4 (B7.1) or the full number. The number is forgiving about case,
 * spaces, the `TMB-` prefix and Bengali digits.
 */
final class OrderTrackRequest extends ApiFormRequest
{
    private const array BENGALI_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    private const array WESTERN_DIGITS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** e.g. "TMB-26-00042" (also "26-00042"). */
            'number' => ['required', 'string', 'max:32', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || self::canonicalNumber($value) === null) {
                    $fail((string) __('checkout.track.number'));
                }
            }],
            /** The last 4 digits of the phone the order was placed with, or the full number. */
            'phone' => ['required', 'string', 'max:32', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || (self::lastFour($value) === null && ! PhoneNumber::isValid($value))) {
                    $fail((string) __('checkout.track.phone'));
                }
            }],
        ];
    }

    public function number(): string
    {
        return self::canonicalNumber($this->string('number')->toString()) ?? '';
    }

    /** True when `$e164` is the phone the customer typed (full) or ends with the 4 digits they typed. */
    public function phoneMatches(string $e164): bool
    {
        $input = $this->string('phone')->toString();
        $four = self::lastFour($input);
        if ($four !== null) {
            return hash_equals(substr($e164, -4), $four);
        }
        $phone = PhoneNumber::tryParse($input);

        return $phone !== null && hash_equals($e164, $phone->e164());
    }

    /** "tmb 26 00042", "26-00042", "TMB-২৬-০০০৪২" → "TMB-26-00042"; null when it cannot be one. */
    public static function canonicalNumber(string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', str_replace(self::BENGALI_DIGITS, self::WESTERN_DIGITS, $input)) ?? '';
        if (strlen($digits) < 3 || strlen($digits) > 10) {
            return null;
        }

        return OrderNumberGenerator::format(2000 + (int) substr($digits, 0, 2), (int) substr($digits, 2));
    }

    private static function lastFour(string $input): ?string
    {
        $digits = trim(str_replace(self::BENGALI_DIGITS, self::WESTERN_DIGITS, $input));

        return preg_match('/^\d{4}$/', $digits) === 1 ? $digits : null;
    }
}
