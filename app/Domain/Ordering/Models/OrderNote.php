<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Models;

use App\Domain\Ordering\Exceptions\OrderViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A staff note on an order (P4-ORD-05). Append-only: the model refuses update and delete.
 * Private unless `is_customer_visible`. Written only by {@see \App\Domain\Ordering\Actions\AddOrderNote}.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $user_id
 * @property string $body
 * @property bool $is_customer_visible
 * @property Carbon|null $created_at
 */
final class OrderNote extends Model
{
    public const null UPDATED_AT = null;

    public const int MAX_LENGTH = 2000;

    /** @var list<string> */
    protected $fillable = ['order_id', 'user_id', 'body', 'is_customer_visible'];

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
