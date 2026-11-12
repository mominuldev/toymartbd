<?php

declare(strict_types=1);

namespace App\Domain\Finance\DTOs;

use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * One entry for the append-only `financial_transactions` ledger (DATABASE-DESIGN §3.7).
 *
 * `amount` is always positive; `direction` carries the sign. `occurredOn` is a **Dhaka**
 * calendar date (rule 11) — build it with `LedgerEntry::dhakaDateOf()` or {@see DhakaDate}.
 * `isRecognisedRevenue` = belongs in the P&L; `isCashMovement` = real money moved (rule 10).
 *
 * `(sourceType, sourceId, type)` is the idempotency key: recording the same triple twice
 * writes nothing the second time, so a replayed event is a no-op. Two genuinely distinct
 * entries of one type for one source need distinct sources (e.g. one per remittance row).
 */
final readonly class LedgerEntry
{
    public function __construct(
        public LedgerEntryType $type,
        public Direction $direction,
        public Money $amount,
        public CarbonImmutable $occurredOn,
        public string $sourceType,
        public int $sourceId,
        public bool $isCashMovement,
        public bool $isRecognisedRevenue,
        public ?int $categoryId = null,
        public ?string $description = null,
    ) {
        if ($amount->isNegative()) {
            throw new InvalidArgumentException('A ledger amount is never negative; use the direction.');
        }
        if ($sourceType === '' || $sourceId < 1) {
            throw new InvalidArgumentException('A ledger entry needs a source.');
        }
    }

    /** The Dhaka calendar date (midnight, Dhaka zone) an instant falls on: 23:30 UTC is the next day. */
    public static function dhakaDateOf(DateTimeInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', DhakaDate::dayOf($instant), DhakaDate::zone())
            ?? throw new InvalidArgumentException('Unparseable instant.');
    }
}
