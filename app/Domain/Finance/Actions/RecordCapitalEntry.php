<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\CapitalEntryKind;
use App\Domain\Finance\Enums\ExpensePaymentMethod;
use App\Domain\Finance\Exceptions\CapitalEntryRefused;
use App\Domain\Finance\Models\CapitalEntry;
use App\Domain\Finance\Models\CapitalHolder;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Record owner capital put in ("New investment") or a drawing taken out (P10). One row in
 * `capital_entries`, one ledger entry — `owner_capital_in` (in) or `owner_drawing` (out),
 * **cash, never revenue** (rule 10), so the P&L never moves — and one audit row, all in one
 * transaction. The holder row is locked so a deactivation cannot commit in between.
 */
final readonly class RecordCapitalEntry
{
    public function __construct(private Ledger $ledger) {}

    /** @throws CapitalEntryRefused when the holder is inactive */
    public function handle(
        CapitalHolder $holder,
        CapitalEntryKind $kind,
        Money $amount,
        CarbonImmutable $occurredOn,
        ExpensePaymentMethod $method,
        ?string $reference,
        ?string $note,
        ?int $userId,
    ): CapitalEntry {
        return DB::transaction(function () use ($holder, $kind, $amount, $occurredOn, $method, $reference, $note, $userId): CapitalEntry {
            $locked = CapitalHolder::query()->lockForUpdate()->findOrFail($holder->id);
            if (! $locked->is_active) {
                throw CapitalEntryRefused::inactiveHolder();
            }

            $entry = CapitalEntry::query()->create([
                'kind' => $kind,
                'holder_id' => $locked->id,
                'amount' => $amount,
                'occurred_on' => $occurredOn->toDateString(),
                'method' => $method,
                'reference' => $reference,
                'note' => $note,
                'recorded_by' => $userId,
            ]);

            $this->ledger->record(new LedgerEntry(
                type: $kind->ledgerType(),
                direction: $kind->direction(),
                amount: $amount,
                occurredOn: $occurredOn,
                sourceType: CapitalEntry::SOURCE,
                sourceId: $entry->id,
                isCashMovement: true,
                isRecognisedRevenue: false,
                description: CapitalEntry::ledgerDescription($locked->name, $method),
            ));

            AuditSensitive::query()->create([
                'user_id' => $userId,
                'action' => AuditAction::CapitalEntryRecorded,
                'model_type' => $entry->getMorphClass(),
                'model_id' => $entry->id,
                'before' => null,
                'after' => [
                    'kind' => $kind->value,
                    'holder_id' => $locked->id,
                    'amount' => $amount->toDecimal(),
                    'occurred_on' => $occurredOn->toDateString(),
                    'method' => $method->value,
                ],
            ]);

            return $entry->load(['holder', 'recorder', 'reversal']);
        });
    }
}
