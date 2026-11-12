<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Support\DhakaDate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The financial ledger writer (rule 8). Entries go in as ONE multi-row INSERT, so a batch
 * lands whole or not at all without this class owning a transaction (rule 3: the caller's
 * Action does). Entries whose `(source_type, source_id, type)` already exists are dropped
 * first, so a replay writes nothing; a concurrent writer that wins the race surfaces as a
 * unique violation, after which the batch is re-filtered once.
 *
 * Afterwards the `order_financials` rows of every order the entries touch are recomputed
 * from the ledger, so the projection never lags the ledger it mirrors.
 */
final readonly class EloquentLedger implements Ledger
{
    public function __construct(
        private OrderLedgerSources $sources,
        private OrderFinancialsProjector $projector,
    ) {}

    public function record(LedgerEntry ...$entries): void
    {
        if ($entries === []) {
            return;
        }

        try {
            $written = $this->insertNew($entries);
        } catch (UniqueConstraintViolationException) {
            $written = $this->insertNew($entries);
        }

        if ($written === []) {
            return;
        }

        $orderIds = $this->sources->orderIdsFor(array_map(
            static fn (LedgerEntry $e): array => [$e->sourceType, $e->sourceId],
            $written,
        ));
        $this->projector->refresh($orderIds);
    }

    /**
     * @param  array<int, LedgerEntry>  $entries
     * @return list<LedgerEntry> the entries actually written
     */
    private function insertNew(array $entries): array
    {
        $fresh = [];
        foreach ($entries as $entry) {
            $fresh[self::key($entry)] = $entry; // a duplicate inside one batch is written once
        }

        $existing = DB::table('financial_transactions')
            ->where(static function ($q) use ($fresh): void {
                foreach ($fresh as $entry) {
                    $q->orWhere(static fn ($w) => $w->where('source_type', $entry->sourceType)
                        ->where('source_id', $entry->sourceId)
                        ->where('type', $entry->type->value));
                }
            })
            ->get(['source_type', 'source_id', 'type']);

        foreach ($existing as $row) {
            unset($fresh[$row->source_type.'|'.$row->source_id.'|'.$row->type]);
        }

        if ($fresh === []) {
            return [];
        }

        $now = DhakaDate::instant();
        DB::table('financial_transactions')->insert(array_values(array_map(static fn (LedgerEntry $e): array => [
            'type' => $e->type->value,
            'direction' => $e->direction->value,
            'amount' => $e->amount->toDecimal(),
            // A Dhaka calendar date (rule 11), whatever zone the caller's Carbon carries.
            'occurred_on' => $e->occurredOn->setTimezone(DhakaDate::zone())->toDateString(),
            'source_type' => $e->sourceType,
            'source_id' => $e->sourceId,
            'category_id' => $e->categoryId,
            'description' => $e->description === null ? null : mb_substr($e->description, 0, 500),
            'is_cash_movement' => $e->isCashMovement,
            'is_recognised_revenue' => $e->isRecognisedRevenue,
            'created_at' => $now,
        ], $fresh)));

        return array_values($fresh);
    }

    private static function key(LedgerEntry $entry): string
    {
        return $entry->sourceType.'|'.$entry->sourceId.'|'.$entry->type->value;
    }
}
