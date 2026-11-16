<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\CapitalEntryKind;
use App\Domain\Finance\Enums\ExpensePaymentMethod;
use App\Domain\Finance\Exceptions\LedgerViolation;
use App\Models\User;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Owner capital put in (`contribution`) or taken out (`drawing`), P10. Insert-only like the
 * ledger it feeds (rule 8): the model refuses update and delete. A mistake is corrected by a
 * reversing row — same kind, holder, amount and method, `reversal_of_id` pointing at the
 * original (UNIQUE, so once only) — whose ledger entry mirrors the original's.
 *
 * @property int $id
 * @property CapitalEntryKind $kind
 * @property int $holder_id
 * @property Money $amount
 * @property Carbon $occurred_on
 * @property ExpensePaymentMethod $method
 * @property string|null $reference
 * @property string|null $note
 * @property int|null $reversal_of_id
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CapitalHolder $holder
 * @property-read User|null $recorder
 * @property-read CapitalEntry|null $reversalOf
 * @property-read CapitalEntry|null $reversal
 */
final class CapitalEntry extends Model
{
    /** Ledger `source_type` of an original entry and of a reversal (short aliases, never class names). */
    public const string SOURCE = 'capital_entry';

    public const string REVERSAL_SOURCE = 'capital_reversal';

    /** @var list<string> */
    protected $fillable = ['kind', 'holder_id', 'amount', 'occurred_on', 'method', 'reference', 'note', 'reversal_of_id', 'recorded_by'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw LedgerViolation::appendOnly());
        self::deleting(static fn (): never => throw LedgerViolation::appendOnly());
    }

    /**
     * The ledger row's description: "Mominul Islam · bKash" (a reversal adds "· reverses #10").
     * Plain names, never an amount; the ledger shows it as written.
     */
    public static function ledgerDescription(string $holderName, ExpensePaymentMethod $method, ?int $reverses = null): string
    {
        $label = match ($method) {
            ExpensePaymentMethod::Cash => 'Cash',
            ExpensePaymentMethod::BankTransfer => 'Bank',
            ExpensePaymentMethod::Bkash => 'bKash',
            ExpensePaymentMethod::Nagad => 'Nagad',
            ExpensePaymentMethod::Card => 'Card',
            ExpensePaymentMethod::Cheque => 'Cheque',
        };

        return $holderName.' · '.$label.($reverses === null ? '' : " · reverses #{$reverses}");
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    /**
     * What the row does to the holder's capital: + for money in, − for money out. A
     * contribution is +, a drawing −, and a reversal the opposite of what it reverses.
     */
    public function netEffect(): Money
    {
        $in = $this->kind === CapitalEntryKind::Contribution;

        return ($in xor $this->isReversal()) ? $this->amount : $this->amount->negate();
    }

    /** @return BelongsTo<CapitalHolder, $this> */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(CapitalHolder::class, 'holder_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<CapitalEntry, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /** @return HasOne<CapitalEntry, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => CapitalEntryKind::class,
            'holder_id' => 'integer',
            'amount' => MoneyCast::class,
            'occurred_on' => 'date',
            'method' => ExpensePaymentMethod::class,
            'reversal_of_id' => 'integer',
            'recorded_by' => 'integer',
        ];
    }
}
