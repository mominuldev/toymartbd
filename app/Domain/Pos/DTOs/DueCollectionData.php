<?php

declare(strict_types=1);

namespace App\Domain\Pos\DTOs;

use App\Support\PhoneNumber;

/** A due collection as the register sent it. Every figure is re-derived on the server. */
final readonly class DueCollectionData
{
    /** @param  list<TenderInput>  $tenders */
    public function __construct(
        public int $sessionId,
        public PhoneNumber $phone,
        public array $tenders,
        public int $cashierId,
        public ?string $idempotencyKey = null,
    ) {}
}
