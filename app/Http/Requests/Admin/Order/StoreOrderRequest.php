<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Order;

use App\Domain\Ordering\DTOs\ManualOrderData;
use App\Domain\Ordering\Enums\OrderSource;
use App\Domain\Ordering\Models\Order;
use App\Http\Requests\Admin\Order\Concerns\ValidatesOrderComposition;
use App\Http\Requests\ApiFormRequest;
use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /orders` — a phone / Facebook / WhatsApp order keyed in by staff (P4-ORD-06).
 * Needs orders.create and an `Idempotency-Key`. Payment is cash on delivery (the only
 * driver until a gateway is chosen, Gate 1 decision 5). `expected_total` is the total
 * from `POST /orders/quote` that the operator read to the customer.
 */
final class StoreOrderRequest extends ApiFormRequest
{
    use ValidatesOrderComposition;

    /** Channels staff can key an order in for; `web` is the storefront's own. */
    public const array SOURCES = [OrderSource::Phone, OrderSource::Facebook, OrderSource::Whatsapp, OrderSource::Admin];

    public function authorize(): bool
    {
        return $this->user()?->can('create', Order::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->lineRules(),
            ...$this->recipientRules(),
            /** Where the order came from. */
            'source' => ['required', 'string', Rule::in(array_map(static fn (OrderSource $s): string => $s->value, self::SOURCES))],
            'coupon_code' => ['nullable', 'string', 'max:64'],
            /** Printed on the packing slip (e.g. "gift wrap please"). */
            'customer_note' => ['nullable', 'string', 'max:500'],
            /** Staff only — never shown to the customer. */
            'internal_note' => ['nullable', 'string', 'max:1000'],
            'expected_total' => self::totalRule(),
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addressAfter($validator)];
    }

    public function toData(): ManualOrderData
    {
        return new ManualOrderData(
            lines: $this->lines(),
            name: $this->string('name')->trim()->toString(),
            phone: $this->phone(),
            district: $this->district(),
            upazila: $this->upazila(),
            area: $this->string('area')->trim()->toString(),
            addressLine: $this->string('address_line')->trim()->toString(),
            landmark: $this->string('landmark')->trim()->toString(),
            expectedTotal: $this->expectedTotal(),
            source: OrderSource::from($this->string('source')->toString()),
            couponCode: $this->text('coupon_code'),
            altPhone: $this->altPhone(),
            customerNote: $this->text('customer_note'),
            internalNote: $this->text('internal_note'),
            userId: $this->user()?->id,
            locale: App::getLocale(),
        );
    }
}
