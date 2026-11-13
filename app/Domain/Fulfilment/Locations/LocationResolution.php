<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Locations;

use App\Domain\Fulfilment\DTOs\CourierLocationRef;
use App\Domain\Fulfilment\Models\CourierLocation;

/**
 * The answer to "which courier ids does this address book into?".
 *
 *   not_required — the provider takes a free-text address (Steadfast): book without ids.
 *   resolved     — exactly one human-mapped zone fits: `ref` is what the driver sends.
 *   ambiguous    — several mapped zones fit (Dhaka city maps to many): staff must pick one
 *                  of `candidates` at booking. Never guessed.
 *   unmapped     — nothing mapped for this district/upazila: booking is blocked and the
 *                  address appears in the admin's "needs mapping" list.
 */
final readonly class LocationResolution
{
    public const string NOT_REQUIRED = 'not_required';

    public const string RESOLVED = 'resolved';

    public const string AMBIGUOUS = 'ambiguous';

    public const string UNMAPPED = 'unmapped';

    /** @param  list<CourierLocation>  $candidates */
    private function __construct(
        public string $status,
        public ?CourierLocationRef $ref = null,
        public ?CourierLocation $location = null,
        public array $candidates = [],
    ) {}

    public static function notRequired(): self
    {
        return new self(self::NOT_REQUIRED);
    }

    public static function resolved(CourierLocation $location, CourierLocationRef $ref): self
    {
        return new self(self::RESOLVED, $ref, $location, [$location]);
    }

    /** @param  list<CourierLocation>  $candidates */
    public static function ambiguous(array $candidates): self
    {
        return new self(self::AMBIGUOUS, candidates: $candidates);
    }

    public static function unmapped(): self
    {
        return new self(self::UNMAPPED);
    }

    /** True when a booking may proceed (with `ref`, or without ids for a free-text provider). */
    public function canBook(): bool
    {
        return $this->status === self::RESOLVED || $this->status === self::NOT_REQUIRED;
    }
}
