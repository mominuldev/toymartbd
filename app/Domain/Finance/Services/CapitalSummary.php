<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\CapitalEntryKind;
use App\Domain\Finance\Models\CapitalEntry;
use App\Domain\Finance\Models\CapitalHolder;
use App\Domain\Finance\Reports\ReportMoney;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The owner-capital overview (P10): per holder, what was put in, what was taken out, the net
 * and the latest entry, plus the totals. Read-only. Every figure is computed here — the admin
 * only formats it (rule 1). A reversal counts against the kind it reverses, so a reversed
 * contribution adds nothing to "put in".
 *
 * Holders come active first, owners before partners, then by name.
 *
 * Filters: one holder, one kind, a Dhaka date range on `occurred_on` (rule 11). Without a
 * holder filter every holder is listed, inactive ones included (their history still counts).
 */
final class CapitalSummary
{
    /**
     * @return array{
     *     holders: list<array{holder: CapitalHolder, put_in: Money, taken_out: Money, net: Money, entries_count: int, last_entry: CapitalEntry|null}>,
     *     totals: array{put_in: Money, taken_out: Money, net: Money, entries_count: int, last_entry: array{entry: CapitalEntry, holder: CapitalHolder}|null},
     * }
     */
    public function summary(?int $holderId, ?CapitalEntryKind $kind, ?string $from, ?string $to): array
    {
        $scope = static function (Builder $q) use ($holderId, $kind, $from, $to): void {
            $q->when($holderId !== null, static fn (Builder $w) => $w->where('holder_id', $holderId))
                ->when($kind !== null, static fn (Builder $w) => $w->where('kind', $kind?->value))
                ->when($from !== null, static fn (Builder $w) => $w->where('occurred_on', '>=', $from))
                ->when($to !== null, static fn (Builder $w) => $w->where('occurred_on', '<=', $to));
        };

        $sums = CapitalEntry::query()->tap($scope)
            ->groupBy('holder_id', 'kind', DB::raw('reversal_of_id IS NULL'))
            ->select('holder_id', 'kind')
            ->selectRaw('reversal_of_id IS NULL as original')
            ->selectRaw('SUM(amount) as total')
            ->selectRaw('COUNT(*) as entries')
            ->toBase()
            ->get();

        /** @var array<int, array{put_in: Money, taken_out: Money, entries_count: int}> $byHolder */
        $byHolder = [];
        foreach ($sums as $row) {
            $id = (int) $row->holder_id;
            $byHolder[$id] ??= ['put_in' => Money::zero(), 'taken_out' => Money::zero(), 'entries_count' => 0];
            $amount = ReportMoney::of($row->total);
            $signed = (bool) $row->original ? $amount : $amount->negate();
            $key = $row->kind === CapitalEntryKind::Contribution->value ? 'put_in' : 'taken_out';
            $byHolder[$id][$key] = $byHolder[$id][$key]->plus($signed);
            $byHolder[$id]['entries_count'] += (int) $row->entries;
        }

        $holders = CapitalHolder::query()
            ->when($holderId !== null, static fn (Builder $q) => $q->whereKey($holderId))
            // Active first, owners before partners, then by name.
            ->orderByDesc('is_active')->orderBy('role')->orderBy('name')
            ->get();

        $rows = [];
        $putIn = $takenOut = Money::zero();
        $count = 0;
        $latest = null;
        foreach ($holders as $holder) {
            $figures = $byHolder[$holder->id] ?? ['put_in' => Money::zero(), 'taken_out' => Money::zero(), 'entries_count' => 0];
            $last = $figures['entries_count'] === 0 ? null : CapitalEntry::query()->tap($scope)
                ->where('holder_id', $holder->id)
                ->orderByDesc('occurred_on')->orderByDesc('id')
                ->first();
            $rows[] = [
                'holder' => $holder,
                'put_in' => $figures['put_in'],
                'taken_out' => $figures['taken_out'],
                'net' => $figures['put_in']->minus($figures['taken_out']),
                'entries_count' => $figures['entries_count'],
                'last_entry' => $last,
            ];
            if ($last !== null && ($latest === null || [$last->occurred_on->toDateString(), $last->id] > [$latest['entry']->occurred_on->toDateString(), $latest['entry']->id])) {
                $latest = ['entry' => $last, 'holder' => $holder];
            }
            $putIn = $putIn->plus($figures['put_in']);
            $takenOut = $takenOut->plus($figures['taken_out']);
            $count += $figures['entries_count'];
        }

        return [
            'holders' => $rows,
            'totals' => ['put_in' => $putIn, 'taken_out' => $takenOut, 'net' => $putIn->minus($takenOut), 'entries_count' => $count, 'last_entry' => $latest],
        ];
    }
}
