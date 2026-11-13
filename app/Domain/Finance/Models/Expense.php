<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\ExpensePaymentMethod;
use App\Domain\Finance\Enums\ExpenseRecurrence;
use App\Domain\Finance\Enums\ExpenseStatus;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A business expense (DATABASE-DESIGN §3.7, PRD N-04). On the ledger only once approved;
 * an approved expense is immutable — a mistake is voided (reversing entry) and re-entered.
 *
 * @property int $id
 * @property int $category_id
 * @property Money $amount
 * @property Carbon $expense_date
 * @property ExpenseStatus $status
 * @property ExpensePaymentMethod|null $payment_method
 * @property Carbon|null $paid_on
 * @property string|null $reference
 * @property string|null $vendor
 * @property string|null $description
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 * @property string|null $attachment_mime
 * @property int|null $attachment_size
 * @property bool $is_recurring
 * @property ExpenseRecurrence|null $recurrence
 * @property int|null $recurrence_parent_id
 * @property string|null $recurrence_period
 * @property int|null $order_id
 * @property int|null $shipment_id
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $rejected_reason
 * @property int|null $voided_by
 * @property Carbon|null $voided_at
 * @property string|null $void_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ExpenseCategory $category
 */
final class Expense extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'category_id', 'amount', 'expense_date', 'status', 'payment_method', 'paid_on', 'reference',
        'vendor', 'description', 'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size',
        'is_recurring', 'recurrence', 'recurrence_parent_id', 'recurrence_period', 'order_id', 'shipment_id',
        'created_by', 'approved_by', 'approved_at', 'rejected_reason', 'voided_by', 'voided_at', 'void_reason',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending', 'is_recurring' => false];

    public function isPaid(): bool
    {
        return $this->payment_method !== null;
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'amount' => MoneyCast::class,
            'expense_date' => 'date',
            'status' => ExpenseStatus::class,
            'payment_method' => ExpensePaymentMethod::class,
            'paid_on' => 'date',
            'attachment_size' => 'integer',
            'is_recurring' => 'boolean',
            'recurrence' => ExpenseRecurrence::class,
            'recurrence_parent_id' => 'integer',
            'order_id' => 'integer',
            'shipment_id' => 'integer',
            'created_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'voided_by' => 'integer',
            'voided_at' => 'datetime',
        ];
    }
}
