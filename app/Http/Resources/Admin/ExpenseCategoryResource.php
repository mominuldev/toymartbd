<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Finance\Models\ExpenseCategory;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * An expense category. `ledger_type` is what its approved expenses post as (`resolved_ledger_type`
 * when the category names none).
 *
 * @property ExpenseCategory $resource
 */
#[SchemaName('ExpenseCategory')]
final class ExpenseCategoryResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $c = $this->resource;

        return [
            'id' => $c->id,
            'name' => $c->name,
            'name_bn' => $c->name_bn,
            /** @var 'cogs'|'operating'|'marketing'|'logistics'|'other' */
            'type' => $c->type->value,
            /** @var string|null */
            'ledger_type' => $c->ledger_type?->value,
            /** @var string */
            'resolved_ledger_type' => $c->resolvedLedgerType()->value,
            'is_fixed' => $c->is_fixed,
            'is_active' => $c->is_active,
            'parent_id' => $c->parent_id,
            /** @var int */
            'expenses_count' => (int) ($c->getAttribute('expenses_count') ?? 0),
        ];
    }
}
