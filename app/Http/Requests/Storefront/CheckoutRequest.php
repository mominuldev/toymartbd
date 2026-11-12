<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Domain\Fulfilment\Services\DeliveryChargeResolver;
use App\Domain\Ordering\Checkout\CheckoutData;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Http\Requests\Storefront\Cart\CartRequest;
use App\Support\Money;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /storefront/checkout` (P4-STO-09, docs/api/checkout.md). The cart comes from
 * `X-Cart-Token`; the body is what the customer typed plus `expected_total`, the grand
 * total they were shown — compared, never used, by PlaceOrder.
 *
 * Address: division → district → upazila → area + line + landmark (rule 31). The
 * upazila is required unless the district offers its city as the empty option
 * (`city_option` on GET /districts — Dhaka city is not an upazila).
 */
final class CheckoutRequest extends CartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'phone' => ['required', 'string', 'max:32', self::phoneRule()],
            'alt_phone' => ['nullable', 'string', 'max:32', self::phoneRule()],
            'email' => ['nullable', 'string', 'email', 'max:191'],
            'division_id' => ['nullable', 'integer', Rule::exists('divisions', 'id')],
            'district_id' => ['required', 'integer', Rule::exists('districts', 'id')],
            'upazila_id' => ['nullable', 'integer', Rule::exists('upazilas', 'id')],
            'area' => ['required', 'string', 'min:2', 'max:160'],
            'address_line' => ['required', 'string', 'min:5', 'max:500'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'payment_method' => ['required', 'string', Rule::in([PaymentMethod::Cod->value])],
            'customer_note' => ['nullable', 'string', 'max:500'],
            /** The grand total the customer was shown, e.g. "1930.00". */
            'expected_total' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'utm_source' => ['nullable', 'string', 'max:128'],
            'utm_medium' => ['nullable', 'string', 'max:128'],
            'utm_campaign' => ['nullable', 'string', 'max:128'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'payment_method.in' => (string) __('checkout.payment_method.unsupported'),
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['district_id', 'upazila_id', 'division_id'])) {
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
        }];
    }

    public function district(): District
    {
        return District::query()->with('division')->findOrFail($this->integer('district_id'));
    }

    public function upazila(): ?Upazila
    {
        return $this->filled('upazila_id') ? Upazila::query()->findOrFail($this->integer('upazila_id')) : null;
    }

    public function toData(): CheckoutData
    {
        $nullable = fn (string $key): ?string => $this->filled($key) ? $this->string($key)->trim()->toString() : null;
        $altPhone = $nullable('alt_phone');

        return new CheckoutData(
            cartToken: $this->requiredCartToken(),
            name: $this->string('name')->trim()->toString(),
            phone: PhoneNumber::parse($this->string('phone')->toString()),
            district: $this->district(),
            upazila: $this->upazila(),
            area: $this->string('area')->trim()->toString(),
            addressLine: $this->string('address_line')->trim()->toString(),
            expectedTotal: Money::of($this->string('expected_total')->toString()),
            paymentMethod: PaymentMethod::from($this->string('payment_method')->toString()),
            altPhone: $altPhone === null ? null : PhoneNumber::parse($altPhone),
            email: $nullable('email'),
            landmark: $nullable('landmark'),
            postcode: $nullable('postcode'),
            customerNote: $nullable('customer_note'),
            ipAddress: $this->ip(),
            userAgent: $this->userAgent(),
            utm: ['source' => $nullable('utm_source'), 'medium' => $nullable('utm_medium'), 'campaign' => $nullable('utm_campaign')],
            locale: App::getLocale(),
        );
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
