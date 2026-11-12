<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Order;

use App\Domain\Ordering\DTOs\OrderEditData;
use App\Domain\Ordering\Models\Order;
use App\Http\Requests\Admin\Order\Concerns\ValidatesOrderComposition;
use App\Http\Requests\ApiFormRequest;
use Closure;
use Illuminate\Validation\Validator;

/**
 * `PUT /orders/{order}` and `POST /orders/{order}/edit-quote` — edit a placed order
 * (P4-ORD-07). Needs orders.update. The body is the whole editable state: every line
 * the order should have afterwards (one left out is removed), the recipient and the
 * address. `expected_total` (required to save, ignored by the quote) is the re-quoted
 * total the operator was shown.
 */
final class UpdateOrderRequest extends ApiFormRequest
{
    use ValidatesOrderComposition;

    public function authorize(): bool
    {
        $order = $this->route('order');

        return $order instanceof Order && ($this->user()?->can('update', $order) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->lineRules(),
            ...$this->recipientRules(),
            'expected_total' => $this->routeIs('*.edit-quote') ? ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'] : self::totalRule(),
            /** Why the order was edited — shown on the activity timeline. */
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addressAfter($validator)];
    }

    public function toData(): OrderEditData
    {
        return new OrderEditData(
            lines: $this->lines(),
            name: $this->string('name')->trim()->toString(),
            phone: $this->phone(),
            district: $this->district(),
            upazila: $this->upazila(),
            area: $this->string('area')->trim()->toString(),
            addressLine: $this->string('address_line')->trim()->toString(),
            landmark: $this->string('landmark')->trim()->toString(),
            altPhone: $this->altPhone(),
            expectedTotal: $this->filled('expected_total') ? $this->expectedTotal() : null,
            note: $this->text('note'),
        );
    }
}
