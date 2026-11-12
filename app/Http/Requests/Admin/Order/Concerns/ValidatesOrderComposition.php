<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Order\Concerns;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Domain\Fulfilment\Services\DeliveryChargeResolver;
use App\Support\Money;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared rules for the admin order composer (P4-ORD-06/07): lines, recipient and the
 * address cascade division → district → upazila → area + line + landmark (rule 31).
 * Same constraints as storefront checkout (CheckoutRequest), plus a required landmark —
 * staff are on the phone with the customer and can always ask for one.
 */
trait ValidatesOrderComposition
{
    public const int MAX_LINES = 50;

    public const int MAX_QUANTITY = 99;

    /** @return array<string, mixed> */
    protected function lineRules(bool $required = true): array
    {
        return [
            'lines' => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.variant_id' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],
        ];
    }

    /** @return array<string, mixed> */
    protected function recipientRules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'phone' => ['required', 'string', 'max:32', self::phoneRule()],
            'alt_phone' => ['nullable', 'string', 'max:32', self::phoneRule()],
            'division_id' => ['nullable', 'integer', Rule::exists('divisions', 'id')],
            'district_id' => ['required', 'integer', Rule::exists('districts', 'id')],
            'upazila_id' => ['nullable', 'integer', Rule::exists('upazilas', 'id')],
            'area' => ['required', 'string', 'min:2', 'max:160'],
            'address_line' => ['required', 'string', 'min:5', 'max:500'],
            'landmark' => ['required', 'string', 'min:2', 'max:255'],
        ];
    }

    /**
     * The total the operator was shown, e.g. "1930.00" — compared, never used.
     *
     * @return list<string>
     */
    protected static function totalRule(): array
    {
        return ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'];
    }

    /** Division ↔ district ↔ upazila consistency, as at checkout. */
    protected function addressAfter(Validator $validator): void
    {
        if (! $this->filled('district_id') || $validator->errors()->hasAny(['district_id', 'upazila_id', 'division_id'])) {
            return;
        }
        $district = $this->district();
        $upazila = $this->upazila();

        if ($this->filled('division_id') && $this->integer('division_id') !== $district->division_id) {
            $validator->errors()->add('division_id', (string) __('checkout.division.mismatch'));
        }
        if ($upazila !== null && $upazila->district_id !== $district->id) {
            $validator->errors()->add('upazila_id', (string) __('checkout.upazila.mismatch'));
        }
        if ($upazila === null && ! self::cityOption($district)) {
            $validator->errors()->add('upazila_id', (string) __('checkout.upazila.required'));
        }
    }

    public function district(): District
    {
        return District::query()->with('division')->findOrFail($this->integer('district_id'));
    }

    public function upazila(): ?Upazila
    {
        return $this->filled('upazila_id') ? Upazila::query()->findOrFail($this->integer('upazila_id')) : null;
    }

    /** @return array<int, int> variant id → quantity */
    public function lines(): array
    {
        $lines = [];
        /** @var list<array{variant_id: int|string, quantity: int|string}> $rows */
        $rows = (array) $this->input('lines', []);
        foreach ($rows as $row) {
            $lines[(int) $row['variant_id']] = (int) $row['quantity'];
        }
        ksort($lines);

        return $lines;
    }

    public function phone(): PhoneNumber
    {
        return PhoneNumber::parse($this->string('phone')->toString());
    }

    public function altPhone(): ?PhoneNumber
    {
        $alt = $this->text('alt_phone');

        return $alt === null ? null : PhoneNumber::parse($alt);
    }

    public function expectedTotal(): Money
    {
        return Money::of($this->string('expected_total')->toString());
    }

    /** A trimmed string, or null when empty. */
    public function text(string $key): ?string
    {
        return $this->filled($key) ? $this->string($key)->trim()->toString() : null;
    }

    /** Same rule as GET /districts' `city_option`: an empty upazila is the district's city. */
    private static function cityOption(District $district): bool
    {
        $resolver = app(DeliveryChargeResolver::class);

        return $resolver->requiresUpazila($district) && $resolver->zoneFor($district, null) === DeliveryZone::InsideDhakaMetro;
    }

    private static function phoneRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! PhoneNumber::isValid($value)) {
                $fail((string) __('checkout.phone.invalid'));
            }
        };
    }
}
