<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Purchasing;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Field rules shared by the supplier create and update requests. Phones follow rule 30
 * (013–019 only) and are stored E.164.
 */
final class SupplierRules
{
    public const string MONEY_PATTERN = '/^-?\d{1,12}(\.\d{1,2})?$/';

    /** @return array<string, mixed> */
    public static function fields(): array
    {
        $phone = static function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== null && $value !== '' && (! is_string($value) || ! PhoneNumber::isValid($value))) {
                $fail(__('validation.regex', ['attribute' => str_replace('_', ' ', $attribute)]));
            }
        };

        return [
            'name' => ['string', 'max:160'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20', $phone],
            'alt_phone' => ['nullable', 'string', 'max:20', $phone],
            'email' => ['nullable', 'email', 'max:191'],
            'address' => ['nullable', 'string', 'max:500'],
            'district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')],
            'tin' => ['nullable', 'string', 'max:32'],
            'payment_terms_days' => ['integer', 'min:0', 'max:365'],
            /** What we already owed the supplier at go-live (negative = an advance they hold). */
            'opening_balance' => ['string', 'regex:'.self::MONEY_PATTERN],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public static function phone(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? PhoneNumber::parse($value)->e164() : null;
    }

    public static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
