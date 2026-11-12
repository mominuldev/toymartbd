<?php

declare(strict_types=1);

namespace App\Domain\Ordering\DTOs;

/**
 * One order's result in a bulk action (P4-ORD-08): every requested id gets exactly one,
 * so a partial failure is reported per order, never as a silent all-or-nothing.
 *
 * `outcome`: `done` (moved), `conflict` (the state machine refused the move — usually
 * someone else got there first) or `not_found`.
 */
final readonly class BulkOrderOutcome
{
    public const string DONE = 'done';

    public const string CONFLICT = 'conflict';

    public const string NOT_FOUND = 'not_found';

    public function __construct(
        public int $orderId,
        public ?string $number,
        public string $outcome,
        public ?string $status,
        public ?string $detail = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->outcome === self::DONE;
    }
}
