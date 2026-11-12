<?php

declare(strict_types=1);

namespace App\Domain\Customer\DTOs;

use App\Domain\Customer\Enums\AddressType;
use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Support\PhoneNumber;

/**
 * A saved address as the customer typed it: district → upazila → area + line + landmark
 * (rule 31). The division is the district's own; it is never typed separately.
 */
final readonly class AddressData
{
    public function __construct(
        public AddressType $type,
        public ?string $label,
        public string $recipientName,
        public PhoneNumber $phone,
        public District $district,
        public ?Upazila $upazila,
        public string $area,
        public string $addressLine,
        public ?string $landmark,
        public bool $makeDefault,
    ) {}
}
