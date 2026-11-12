<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Finance\Models\Expense;
use App\Http\Resources\ApiResource;
use DateTimeInterface;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * An expense (PRD N-04). Money is a decimal string; dates are Dhaka dates; times ISO 8601 UTC.
 * The receipt is never linked publicly: `receipt` says whether one exists, and the file is at
 * `GET /finance/expenses/{id}/receipt` (download only). `can` tells the screen which actions
 * this viewer may take (the server still enforces each).
 *
 * @property Expense $resource
 */
#[SchemaName('Expense')]
final class ExpenseResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $e = $this->resource;
        $user = $request->user();
        $iso = static fn (?DateTimeInterface $at): ?string => $at === null ? null : \Carbon\CarbonImmutable::instance($at)->utc()->format('Y-m-d\TH:i:s\Z');

        return [
            'id' => $e->id,
            'category' => new ExpenseCategoryResource($e->category),
            /** @var string */
            'amount' => $e->amount->toDecimal(),
            /** @var string */
            'expense_date' => $e->expense_date->toDateString(),
            /** @var 'pending'|'approved'|'rejected'|'void' */
            'status' => $e->status->value,
            /** @var 'cash'|'bank_transfer'|'bkash'|'nagad'|'card'|'cheque'|null */
            'payment_method' => $e->payment_method?->value,
            /** @var string|null */
            'paid_on' => $e->paid_on?->toDateString(),
            'reference' => $e->reference,
            'vendor' => $e->vendor,
            'description' => $e->description,
            /** @var array{name: string, mime: string, size: int}|null */
            'receipt' => $e->attachment_path === null ? null : [
                'name' => (string) $e->attachment_name,
                'mime' => (string) $e->attachment_mime,
                'size' => (int) $e->attachment_size,
            ],
            'is_recurring' => $e->is_recurring,
            /** @var 'monthly'|'quarterly'|'yearly'|null */
            'recurrence' => $e->recurrence?->value,
            'recurrence_parent_id' => $e->recurrence_parent_id,
            'recurrence_period' => $e->recurrence_period,
            'order_id' => $e->order_id,
            'shipment_id' => $e->shipment_id,
            'created_by' => $e->created_by,
            'approved_by' => $e->approved_by,
            /** @var string|null */
            'approved_at' => $iso($e->approved_at),
            'rejected_reason' => $e->rejected_reason,
            /** @var string|null */
            'voided_at' => $iso($e->voided_at),
            'void_reason' => $e->void_reason,
            /** @var string|null */
            'created_at' => $iso($e->created_at),
            /** @var array{update: bool, delete: bool, approve: bool, reject: bool, void: bool, pay: bool} */
            'can' => [
                'update' => $e->status->isEditable() && ($user?->can('update', $e) ?? false),
                'delete' => $e->status->isEditable() && ($user?->can('delete', $e) ?? false),
                'approve' => $e->status->value === 'pending' && ($user?->can('approve', $e) ?? false),
                'reject' => $e->status->value === 'pending' && ($user?->can('reject', $e) ?? false),
                'void' => $e->status->value === 'approved' && ($user?->can('void', $e) ?? false),
                'pay' => ! $e->isPaid() && in_array($e->status->value, ['pending', 'approved'], true) && ($user?->can('pay', $e) ?? false),
            ],
        ];
    }
}
