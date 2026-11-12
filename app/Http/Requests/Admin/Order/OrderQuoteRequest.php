<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Order;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Ordering\Models\Order;
use App\Http\Requests\Admin\Order\Concerns\ValidatesOrderComposition;
use App\Http\Requests\ApiFormRequest;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /orders/quote` — price a new staff order through the checkout pipeline without
 * writing anything (P4-ORD-06). Needs orders.create. Everything but the lines is
 * optional: without a district there is no delivery line; without a phone the
 * per-customer coupon checks wait for the save.
 */
final class OrderQuoteRequest extends ApiFormRequest
{
    use ValidatesOrderComposition;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Order::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->lineRules(),
            'coupon_code' => ['nullable', 'string', 'max:64'],
            /** Optional; enables the per-customer and first-order coupon checks. Ignored when not a valid number yet. */
            'phone' => ['nullable', 'string', 'max:32'],
            'district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')],
            'upazila_id' => ['nullable', 'integer', Rule::exists('upazilas', 'id')],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->filled('upazila_id') && $this->filled('district_id') && ! $validator->errors()->hasAny(['district_id', 'upazila_id'])
                && $this->upazila()?->district_id !== $this->integer('district_id')) {
                $validator->errors()->add('upazila_id', (string) __('checkout.upazila.mismatch'));
            }
        }];
    }

    public function quoteDistrict(): ?District
    {
        return $this->filled('district_id') ? $this->district() : null;
    }

    public function quoteUpazila(): ?Upazila
    {
        return $this->upazila();
    }

    public function quotePhone(): ?PhoneNumber
    {
        $phone = $this->text('phone');

        return $phone === null ? null : PhoneNumber::tryParse($phone);
    }
}
