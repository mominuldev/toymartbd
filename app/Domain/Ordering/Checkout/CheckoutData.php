<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Checkout;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Ordering\Enums\OrderSource;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Support\Money;
use App\Support\PhoneNumber;

/**
 * A validated checkout submission (docs/api/checkout.md) — what the customer typed plus
 * the total they were shown. Built by the HTTP layer after validation; PlaceOrder never
 * trusts `expectedTotal` for money, it only compares it with its own re-priced total.
 *
 * `upazila` null = the district's city (only for a `city_option` district).
 */
final readonly class CheckoutData
{
    /** @param  array{source?: string|null, medium?: string|null, campaign?: string|null}  $utm */
    public function __construct(
        public string $cartToken,
        public string $name,
        public PhoneNumber $phone,
        public District $district,
        public ?Upazila $upazila,
        public string $area,
        public string $addressLine,
        public Money $expectedTotal,
        public PaymentMethod $paymentMethod = PaymentMethod::Cod,
        public ?PhoneNumber $altPhone = null,
        public ?string $email = null,
        public ?string $landmark = null,
        public ?string $postcode = null,
        public ?string $customerNote = null,
        public OrderSource $source = OrderSource::Web,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public array $utm = [],
        public string $locale = 'bn',
    ) {}
}
