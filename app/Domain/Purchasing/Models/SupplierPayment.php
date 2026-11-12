<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Purchasing\Enums\SupplierPaymentMethod;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Money paid to a supplier (DATABASE-DESIGN §3.2). Immutable once written — it is the
 * source of an append-only `supplier_payment` ledger entry (rule 8).
 *
 * @property int $id
 * @property int $supplier_id
 * @property int|null $purchase_id
 * @property Money $amount
 * @property SupplierPaymentMethod $method
 * @property string|null $reference
 * @property Carbon $paid_at
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Supplier $supplier
 * @property-read Purchase|null $purchase
 */
final class SupplierPayment extends Model
{
    /** `financial_transactions.source_type` for the ledger entry a payment writes. */
    public const string SOURCE_TYPE = 'supplier_payment';

    /** @var list<string> */
    protected $fillable = ['supplier_id', 'purchase_id', 'amount', 'method', 'reference', 'paid_at', 'note', 'created_by'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('A supplier payment is never changed; record a correcting entry instead.'));
        self::deleting(static fn (): never => throw new LogicException('A supplier payment is never deleted.'));
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'purchase_id' => 'integer',
            'created_by' => 'integer',
            'amount' => MoneyCast::class,
            'method' => SupplierPaymentMethod::class,
            'paid_at' => 'date',
        ];
    }
}
