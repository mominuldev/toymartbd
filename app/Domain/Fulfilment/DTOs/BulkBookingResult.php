<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\DTOs;

/**
 * A bulk submission's answer. Neither provider returns consignment ids from bulk
 * (rule 24d), so success only means "submitted": each shipment stays `submitted` until a
 * webhook or the poll confirms it. `refs` lists the merchant order refs the provider
 * acknowledged (in request order where the provider says nothing more).
 */
final readonly class BulkBookingResult
{
    /** @param  list<string>  $refs */
    public function __construct(
        public bool $submitted,
        public ?string $error = null,
        public array $refs = [],
    ) {}
}
