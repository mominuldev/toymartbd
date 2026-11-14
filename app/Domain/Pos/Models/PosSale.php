<?php

declare(strict_types=1);

namespace App\Domain\Pos\Models;

use App\Domain\Ordering\Models\Order;
use App\Models\User;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One charged counter sale: the drawer session ↔ the order it created, plus what only the
 * counter knows (cash handed over, change given, the Charge request's Idempotency-Key).
 * Written once by {@see \App\Domain\Pos\Actions\ChargeSale}; never updated or deleted.
 *
 * @property int $id
 * @property int $session_id
 * @property int $order_id
 * @property string|null $idempotency_key
 * @property int $cashier_id
 * @property Money $grand_total
 * @property Money $tendered_total
 * @property Money $cash_tendered
 * @property Money $change_due
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PosSession $session
 * @property-read Order $order
 * @property-read User $cashier
 */
final class PosSale extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('A POS sale is written once.'));
        self::deleting(static fn (): never => throw new LogicException('A POS sale is never deleted.'));
    }

    /** @return BelongsTo<PosSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'session_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'session_id' => 'integer',
            'order_id' => 'integer',
            'cashier_id' => 'integer',
            'grand_total' => MoneyCast::class,
            'tendered_total' => MoneyCast::class,
            'cash_tendered' => MoneyCast::class,
            'change_due' => MoneyCast::class,
        ];
    }
}
