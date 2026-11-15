<?php

declare(strict_types=1);

namespace App\Domain\Pos\Models;

use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Models\Payment;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One FIFO slice of a due collection: the payment written on one order. Written once.
 *
 * @property int $id
 * @property int $collection_id
 * @property int $order_id
 * @property int $payment_id
 * @property PaymentMethod $method
 * @property Money $amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PosDueCollection $collection
 * @property-read Order $order
 * @property-read Payment $payment
 */
final class PosDueCollectionAllocation extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('A due allocation is written once.'));
        self::deleting(static fn (): never => throw new LogicException('A due allocation is never deleted.'));
    }

    /** @return BelongsTo<PosDueCollection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(PosDueCollection::class, 'collection_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'collection_id' => 'integer',
            'order_id' => 'integer',
            'payment_id' => 'integer',
            'method' => PaymentMethod::class,
            'amount' => MoneyCast::class,
        ];
    }
}
