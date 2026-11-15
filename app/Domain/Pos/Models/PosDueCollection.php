<?php

declare(strict_types=1);

namespace App\Domain\Pos\Models;

use App\Domain\Customer\Models\Customer;
use App\Models\User;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Due (baki) a customer paid at the counter, on an open drawer (owner decision 2026-11-15).
 * The money is the payments its allocations wrote; this row carries what only the counter
 * knows (cash handed over, change, the Idempotency-Key). Written once by
 * {@see \App\Domain\Pos\Actions\CollectDue}; never updated or deleted.
 *
 * @property int $id
 * @property int $session_id
 * @property int $customer_id
 * @property string|null $idempotency_key
 * @property int $cashier_id
 * @property Money $collected
 * @property Money $tendered_total
 * @property Money $cash_tendered
 * @property Money $change_due
 * @property Money $remaining_due
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PosSession $session
 * @property-read Customer $customer
 * @property-read User $cashier
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PosDueCollectionAllocation> $allocations
 */
final class PosDueCollection extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('A due collection is written once.'));
        self::deleting(static fn (): never => throw new LogicException('A due collection is never deleted.'));
    }

    /** @return BelongsTo<PosSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'session_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /** @return HasMany<PosDueCollectionAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PosDueCollectionAllocation::class, 'collection_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'session_id' => 'integer',
            'customer_id' => 'integer',
            'cashier_id' => 'integer',
            'collected' => MoneyCast::class,
            'tendered_total' => MoneyCast::class,
            'cash_tendered' => MoneyCast::class,
            'change_due' => MoneyCast::class,
            'remaining_due' => MoneyCast::class,
        ];
    }
}
