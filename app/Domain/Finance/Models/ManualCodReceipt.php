<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Exceptions\LedgerViolation;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Cash received from a courier for one delivered COD order, recorded by hand until the
 * Phase 6 settlement import exists. Insert-only: a mistake is corrected by the ledger's
 * reversing entry, never by editing the receipt.
 *
 * @property int $id
 * @property int $order_id
 * @property Money $amount
 * @property Carbon $received_on
 * @property string|null $reference
 * @property string|null $note
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 */
final class ManualCodReceipt extends Model
{
    public const null UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['order_id', 'amount', 'received_on', 'reference', 'note', 'recorded_by'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw LedgerViolation::appendOnly());
        self::deleting(static fn (): never => throw LedgerViolation::appendOnly());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'amount' => MoneyCast::class,
            'received_on' => 'date',
            'recorded_by' => 'integer',
        ];
    }
}
