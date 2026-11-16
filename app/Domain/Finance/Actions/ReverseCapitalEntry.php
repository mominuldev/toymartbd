<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Exceptions\CapitalEntryRefused;
use App\Domain\Finance\Models\CapitalEntry;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Support\DhakaDate;
use Illuminate\Support\Facades\DB;

/**
 * Reverse an owner-capital entry (P10): a new `capital_entries` row (same kind, holder,
 * amount and method; `reversal_of_id` = the original; the reason as its note) and the exact
 * mirror ledger entry — same type, opposite direction, source `capital_reversal` — dated today
 * in Dhaka. The original stays on its own day (rule 8, never rewritten).
 *
 * Once only: the original is locked and checked, and `reversal_of_id` is UNIQUE, so a second
 * reversal (or a race between two) is refused. A reversal itself cannot be reversed — record
 * a new entry instead. Reversing is allowed for an inactive holder: it is a correction.
 */
final readonly class ReverseCapitalEntry
{
    public function __construct(private Ledger $ledger) {}

    /** @throws CapitalEntryRefused */
    public function handle(CapitalEntry $entry, string $reason, ?int $userId): CapitalEntry
    {
        return DB::transaction(function () use ($entry, $reason, $userId): CapitalEntry {
            $original = CapitalEntry::query()->with('holder')->lockForUpdate()->findOrFail($entry->id);
            if ($original->isReversal()) {
                throw CapitalEntryRefused::isReversal();
            }
            if (CapitalEntry::query()->where('reversal_of_id', $original->id)->exists()) {
                throw CapitalEntryRefused::alreadyReversed();
            }

            $today = DhakaDate::todayDate();
            $reversal = CapitalEntry::query()->create([
                'kind' => $original->kind,
                'holder_id' => $original->holder_id,
                'amount' => $original->amount,
                'occurred_on' => $today->toDateString(),
                'method' => $original->method,
                'reference' => $original->reference,
                'note' => $reason,
                'reversal_of_id' => $original->id,
                'recorded_by' => $userId,
            ]);

            $this->ledger->record(new LedgerEntry(
                type: $original->kind->ledgerType(),
                direction: $original->kind->direction() === Direction::In ? Direction::Out : Direction::In,
                amount: $original->amount,
                occurredOn: $today,
                sourceType: CapitalEntry::REVERSAL_SOURCE,
                sourceId: $reversal->id,
                isCashMovement: true,
                isRecognisedRevenue: false,
                description: CapitalEntry::ledgerDescription($original->holder->name, $original->method, $original->id),
            ));

            AuditSensitive::query()->create([
                'user_id' => $userId,
                'action' => AuditAction::CapitalEntryReversed,
                'model_type' => $original->getMorphClass(),
                'model_id' => $original->id,
                'before' => ['reversed' => false],
                'after' => [
                    'reversed' => true,
                    'reversal_id' => $reversal->id,
                    'kind' => $original->kind->value,
                    'amount' => $original->amount->toDecimal(),
                    'reason' => $reason,
                ],
            ]);

            return $reversal->load(['holder', 'recorder', 'reversal']);
        });
    }
}
