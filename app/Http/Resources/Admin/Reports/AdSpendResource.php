<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Reports;

use App\Domain\Marketing\Models\AdSpend;
use App\Http\Resources\ApiResource;
use App\Support\DhakaDate;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Ad spend typed in for one campaign and Dhaka month (Marketing ROI). `month` is YYYY-MM;
 * `amount` a decimal string. `entered_by` / `changed_by` are staff names only, `entered_on`
 * the Dhaka date it was typed in. `can` says what this viewer may do with it.
 *
 * @property AdSpend $resource
 */
#[SchemaName('AdSpend')]
final class AdSpendResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $s = $this->resource;
        $creator = $s->relationLoaded('creator') ? $s->creator : null;
        $updater = $s->relationLoaded('updater') ? $s->updater : null;
        $user = $request->user();

        return [
            'id' => $s->id,
            'campaign' => $s->campaign,
            'source' => $s->source,
            /** @var string YYYY-MM */
            'month' => $s->month->format('Y-m'),
            'amount' => $s->amount->toDecimal(),
            'note' => $s->note,
            /** @var array{id: int, name: string}|null */
            'entered_by' => $creator === null ? null : ['id' => $creator->id, 'name' => $creator->name],
            /** @var array{id: int, name: string}|null */
            'changed_by' => $updater === null ? null : ['id' => $updater->id, 'name' => $updater->name],
            /** @var string|null Dhaka date, YYYY-MM-DD */
            'entered_on' => $s->created_at === null ? null : DhakaDate::dayOf($s->created_at),
            /** @var array{update: bool, delete: bool} */
            'can' => ['update' => $user?->can('update', $s) ?? false, 'delete' => $user?->can('delete', $s) ?? false],
        ];
    }
}
