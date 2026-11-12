<?php

declare(strict_types=1);

namespace App\Domain\Ordering\DTOs;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Support\Money;
use App\Support\PhoneNumber;

/**
 * The whole editable part of a placed order (P4-ORD-07): every line it should have after
 * the edit (variant id → quantity; a line left out is removed), and the recipient and
 * address. `expectedTotal` is the re-quoted total the operator was shown; null only for
 * a preview (the quote endpoint), never for a save.
 */
final readonly class OrderEditData
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
        public ?PhoneNumber $altPhone = null,
        public ?Money $expectedTotal = null,
        public ?string $note = null,
    ) {}
}
