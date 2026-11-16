<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Finance\Models\CapitalEntry;
use App\Domain\Finance\Models\CapitalHolder;
use App\Http\Resources\ApiResource;
use App\Support\Money;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The owner-capital overview (P10): per holder, put in, taken out, net (put in − taken out)
 * and the latest entry, plus totals, over the filtered Dhaka date range. Money is a decimal
 * string. Cash only — none of it is revenue or profit (rule 10).
 *
 * @property array{
 *     from: string|null,
 *     to: string|null,
 *     holders: list<array{holder: CapitalHolder, put_in: Money, taken_out: Money, net: Money, entries_count: int, last_entry: CapitalEntry|null}>,
 *     totals: array{put_in: Money, taken_out: Money, net: Money, entries_count: int, last_entry: array{entry: CapitalEntry, holder: CapitalHolder}|null},
 * } $resource
 */
#[SchemaName('CapitalSummary')]
final class CapitalSummaryResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            /** @var string|null */
            'from' => $s['from'],
            /** @var string|null */
            'to' => $s['to'],
            /** @var list<array{holder: array{id: int, name: string, name_bn: string|null, role: 'owner'|'partner', is_active: bool, has_staff_login: bool}, put_in: string, taken_out: string, net: string, entries_count: int, last_entry: array{id: int, kind: 'contribution'|'drawing', amount: string, net_effect: string, occurred_on: string, is_reversal: bool}|null}> */
            'holders' => array_map(static fn (array $row): array => [
                'holder' => [
                    'id' => $row['holder']->id,
                    'name' => $row['holder']->name,
                    'name_bn' => $row['holder']->name_bn,
                    'role' => $row['holder']->role->value,
                    'is_active' => $row['holder']->is_active,
                    'has_staff_login' => $row['holder']->user_id !== null,
                ],
                'put_in' => $row['put_in']->toDecimal(),
                'taken_out' => $row['taken_out']->toDecimal(),
                'net' => $row['net']->toDecimal(),
                'entries_count' => $row['entries_count'],
                'last_entry' => $row['last_entry'] === null ? null : CapitalEntryResource::brief($row['last_entry']),
            ], $s['holders']),
            /** @var array{put_in: string, taken_out: string, net: string, entries_count: int, last_entry: array{id: int, kind: 'contribution'|'drawing', amount: string, net_effect: string, occurred_on: string, is_reversal: bool, holder_id: int, holder_name: string, holder_name_bn: string|null}|null} */
            'totals' => [
                'put_in' => $s['totals']['put_in']->toDecimal(),
                'taken_out' => $s['totals']['taken_out']->toDecimal(),
                'net' => $s['totals']['net']->toDecimal(),
                'entries_count' => $s['totals']['entries_count'],
                'last_entry' => $s['totals']['last_entry'] === null ? null : [
                    ...CapitalEntryResource::brief($s['totals']['last_entry']['entry']),
                    'holder_id' => $s['totals']['last_entry']['holder']->id,
                    'holder_name' => $s['totals']['last_entry']['holder']->name,
                    'holder_name_bn' => $s['totals']['last_entry']['holder']->name_bn,
                ],
            ],
        ];
    }
}
