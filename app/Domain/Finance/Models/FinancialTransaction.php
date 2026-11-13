<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Finance\Exceptions\LedgerViolation;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row of the append-only financial ledger (DATABASE-DESIGN §3.7, rule 8). Written only
 * through {@see \App\Domain\Finance\Contracts\Ledger}. Saving an existing row or deleting
 * one throws: a correction is a new reversing entry.
 *
 * @property int $id
 * @property LedgerEntryType $type
 * @property Direction $direction
 * @property Money $amount
 * @property Carbon $occurred_on
 * @property string $source_type
 * @property int $source_id
 * @property int|null $category_id
 * @property string|null $description
 * @property bool $is_cash_movement
 * @property bool $is_recognised_revenue
 * @property Carbon|null $created_at
 * @property-read ExpenseCategory|null $category
 */
final class FinancialTransaction extends Model
{
    public const null UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'type', 'direction', 'amount', 'occurred_on', 'source_type', 'source_id', 'category_id',
        'description', 'is_cash_movement', 'is_recognised_revenue',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw LedgerViolation::appendOnly());
        self::deleting(static fn (): never => throw LedgerViolation::appendOnly());
    }

    /** The amount signed by direction: in is positive, out negative. */
    public function signedAmount(): Money
    {
        return $this->direction === Direction::In ? $this->amount : $this->amount->negate();
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
            'type' => LedgerEntryType::class,
            'direction' => Direction::class,
            'amount' => MoneyCast::class,
            'occurred_on' => 'date',
            'source_id' => 'integer',
            'category_id' => 'integer',
            'is_cash_movement' => 'boolean',
            'is_recognised_revenue' => 'boolean',
        ];
    }
}
