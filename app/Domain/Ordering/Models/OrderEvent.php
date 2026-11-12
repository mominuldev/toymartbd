<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Models;

use App\Domain\Ordering\Enums\OrderEventType;
use App\Domain\Ordering\Exceptions\OrderViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row of an order's timeline (DATABASE-DESIGN §3.4). Append-only: the model refuses
 * update and delete. `is_customer_visible` decides whether the tracking page shows it.
 *
 * @property int $id
 * @property int $order_id
 * @property OrderEventType $type
 * @property string|null $field
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $description
 * @property array<string, mixed>|null $meta
 * @property int|null $user_id
 * @property bool $is_customer_visible
 * @property Carbon|null $created_at
 */
final class OrderEvent extends Model
{
    public const null UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'order_id', 'type', 'field', 'from_status', 'to_status', 'description', 'meta', 'user_id', 'is_customer_visible',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw OrderViolation::appendOnly());
        self::deleting(static fn (): never => throw OrderViolation::appendOnly());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'type' => OrderEventType::class,
            'meta' => 'array',
            'user_id' => 'integer',
            'is_customer_visible' => 'boolean',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
