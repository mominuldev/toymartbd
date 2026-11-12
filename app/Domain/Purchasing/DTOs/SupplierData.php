<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\DTOs;

use App\Support\Money;

/** A supplier's editable fields, validated by the FormRequest. `phone` values are E.164. */
final readonly class SupplierData
{
    public function __construct(
        public string $name,
        public ?string $contactPerson = null,
        public ?string $phone = null,
        public ?string $altPhone = null,
        public ?string $email = null,
        public ?string $address = null,
        public ?int $districtId = null,
        public ?string $tin = null,
        public int $paymentTermsDays = 0,
        public ?Money $openingBalance = null,
        public ?string $note = null,
    ) {}

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'contact_person' => $this->contactPerson,
            'phone' => $this->phone,
            'alt_phone' => $this->altPhone,
            'email' => $this->email,
            'address' => $this->address,
            'district_id' => $this->districtId,
            'tin' => $this->tin,
            'payment_terms_days' => $this->paymentTermsDays,
            'opening_balance' => $this->openingBalance ?? Money::zero(),
            'note' => $this->note,
        ];
    }
}
