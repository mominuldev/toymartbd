<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\ExpenseCategoryType;
use App\Domain\Finance\Enums\LedgerEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An expense category (DATABASE-DESIGN §3.7). One level of nesting via `parent_id`.
 * Never deleted once used: `DELETE` deactivates.
 *
 * @property int $id
 * @property string $name
 * @property string|null $name_bn
 * @property ExpenseCategoryType $type
 * @property LedgerEntryType|null $ledger_type
 * @property bool $is_fixed
 * @property bool $capitalised stock cost already in landed cost: cash out only, never a P&L expense
 * @property int|null $parent_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ExpenseCategory|null $parent
 */
final class ExpenseCategory extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'name_bn', 'type', 'ledger_type', 'is_fixed', 'capitalised', 'parent_id', 'is_active'];

    /**
     * The ledger type this category's expenses post as. A capitalised category (freight and
     * customs on stock, already in the landed cost) always posts `supplier_payment` — a
     * cash-only type outside the P&L layout and its rollup columns — so the cost cannot
     * reach the P&L twice (once here, once as COGS on sale).
     */
    public function resolvedLedgerType(): LedgerEntryType
    {
        if ($this->capitalised) {
            return LedgerEntryType::SupplierPayment;
        }

        return $this->ledger_type ?? $this->type->defaultLedgerType();
    }

    /** Whether this category's expenses belong in the P&L (false for capitalised stock costs). */
    public function isRecognised(): bool
    {
        return ! $this->capitalised;
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<ExpenseCategory, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'category_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ExpenseCategoryType::class,
            'ledger_type' => LedgerEntryType::class,
            'is_fixed' => 'boolean',
            'capitalised' => 'boolean',
            'is_active' => 'boolean',
            'parent_id' => 'integer',
        ];
    }
}
