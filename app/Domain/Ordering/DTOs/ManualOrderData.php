<?php

declare(strict_types=1);

namespace App\Domain\Ordering\DTOs;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Ordering\Checkout\CheckoutData;
use App\Domain\Ordering\Enums\OrderSource;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Support\Money;
use App\Support\PhoneNumber;

/**
 * A staff-entered order (P4-ORD-06: phone, Facebook, WhatsApp). The same fields as a web
 * checkout plus the lines (instead of a cart), the channel and the staff member. Built
 * by the HTTP layer after validation; `expectedTotal` is the total the quote showed the
 * operator — compared by PlaceOrder, never used as money (rule 1).
 */
final readonly class ManualOrderData
{
    /** @param  array<int, int>  $lines  variant id → quantity */
    public function __construct(
        public array $lines,
        public string $name,
        public PhoneNumber $phone,
        public District $district,
        public ?Upazila $upazila,
        public string $area,
        public string $addressLine,
        public string $landmark,
        public Money $expectedTotal,
        public OrderSource $source = OrderSource::Phone,
        public ?string $couponCode = null,
        public ?PhoneNumber $altPhone = null,
        public ?string $customerNote = null,
        public ?string $internalNote = null,
        public ?int $userId = null,
        public string $locale = 'bn',
    ) {}

    /** The checkout submission PlaceOrder takes, for the staff cart built from the lines. */
    public function toCheckout(string $cartToken): CheckoutData
    {
        return new CheckoutData(
            cartToken: $cartToken,
            name: $this->name,
            phone: $this->phone,
            district: $this->district,
            upazila: $this->upazila,
            area: $this->area,
            addressLine: $this->addressLine,
            expectedTotal: $this->expectedTotal,
            paymentMethod: PaymentMethod::Cod,
            altPhone: $this->altPhone,
            landmark: $this->landmark,
            customerNote: $this->customerNote,
            source: $this->source,
            locale: $this->locale,
        );
    }
}
