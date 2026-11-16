<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Finance\Models\CapitalEntry;
use App\Http\Resources\ApiResource;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One owner-capital entry (P10). `amount` is always positive; `net_effect` is what the row did
 * to the holder's capital, signed (+ put in, − taken out; a reversal the opposite of what it
 * reverses) — computed here so the admin never does the arithmetic (rule 1). `ledger_type`
 * names the cash-only ledger entry it wrote. A reversal has `reversal_of_id`; an entry already
 * reversed has `reversed_by_id`. `can.reverse` combines state and permission.
 *
 * @property CapitalEntry $resource
 */
#[SchemaName('CapitalEntry')]
final class CapitalEntryResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $e = $this->resource;
        $holder = $e->relationLoaded('holder') ? $e->holder : null;
        $recorder = $e->relationLoaded('recorder') ? $e->recorder : null;
        $reversal = $e->relationLoaded('reversal') ? $e->reversal : null;
        $reversible = ! $e->isReversal() && $reversal === null;

        return [
            'id' => $e->id,
            /** @var 'contribution'|'drawing' */
            'kind' => $e->kind->value,
            /** @var array{id: int, name: string, name_bn: string|null, role: 'owner'|'partner', is_active: bool}|null */
            'holder' => $holder === null ? null : ['id' => $holder->id, 'name' => $holder->name, 'name_bn' => $holder->name_bn, 'role' => $holder->role->value, 'is_active' => $holder->is_active],
            /** @var string */
            'amount' => $e->amount->toDecimal(),
            /** @var string */
            'net_effect' => $e->netEffect()->toDecimal(),
            /** @var string */
            'occurred_on' => $e->occurred_on->toDateString(),
            /** @var 'cash'|'bank_transfer'|'bkash'|'nagad'|'card'|'cheque' */
            'method' => $e->method->value,
            'reference' => $e->reference,
            'note' => $e->note,
            /** @var 'owner_capital_in'|'owner_drawing' */
            'ledger_type' => $e->kind->ledgerType()->value,
            'is_reversal' => $e->isReversal(),
            'reversal_of_id' => $e->reversal_of_id,
            /** @var int|null */
            'reversed_by_id' => $reversal?->id,
            /** @var string|null When the reversal was recorded (ISO 8601 UTC); the reversal's own date is its `occurred_on`. */
            'reversed_at' => $reversal?->created_at === null ? null : CarbonImmutable::instance($reversal->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            /** @var string|null The reversal's Dhaka date. */
            'reversed_on' => $reversal?->occurred_on->toDateString(),
            /** @var array{id: int, name: string}|null */
            'recorded_by' => $recorder === null ? null : ['id' => $recorder->id, 'name' => $recorder->name],
            /** @var string|null */
            'created_at' => $e->created_at === null ? null : CarbonImmutable::instance($e->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            /** @var array{reverse: bool} */
            'can' => ['reverse' => $reversible && ($request->user()?->can('reverse', $e) ?? false)],
        ];
    }

    /**
     * The short form used as a holder's `last_entry` on the overview.
     *
     * @return array{id: int, kind: string, amount: string, net_effect: string, occurred_on: string, is_reversal: bool}
     */
    public static function brief(CapitalEntry $e): array
    {
        return [
            'id' => $e->id,
            'kind' => $e->kind->value,
            'amount' => $e->amount->toDecimal(),
            'net_effect' => $e->netEffect()->toDecimal(),
            'occurred_on' => $e->occurred_on->toDateString(),
            'is_reversal' => $e->isReversal(),
        ];
    }
}
