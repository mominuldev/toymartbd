<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Models;

use App\Domain\Ordering\Enums\ReturnReason;
use App\Domain\Ordering\Enums\ReturnStatus;
use App\Domain\Payment\Models\Refund;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A return (RMA) against a delivered order (DATABASE-DESIGN §4.7). Written only by the
 * return Actions in App\Domain\Ordering\Actions\Returns. Never deleted: it is stock and
 * money history.
 *
 * @property int $id
 * @property string $number
 * @property int $order_id
 * @property ReturnStatus $status
 * @property ReturnReason $reason
 * @property string|null $reason_note
 * @property bool $refund_shipping
 * @property int|null $refund_id
 * @property int|null $requested_by
 * @property Carbon|null $received_at
 * @property int|null $inspected_by
 * @property Carbon|null $inspected_at
 * @property Carbon|null $closed_at
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 * @property-read Refund|null $refund
 */
final class OrderReturn extends Model
{
    public const string REFERENCE_TYPE = 'order_return';

    /** @var list<string> */
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('A return is stock and money history and is never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'status' => ReturnStatus::class,
            'reason' => ReturnReason::class,
            'refund_shipping' => 'boolean',
            'refund_id' => 'integer',
            'requested_by' => 'integer',
            'inspected_by' => 'integer',
            'received_at' => 'datetime',
            'inspected_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<OrderReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderReturnItem::class)->orderBy('id');
    }

    /** @return BelongsTo<Refund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }
}
