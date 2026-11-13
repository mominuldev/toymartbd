<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a return. `quantity_resellable` + `quantity_damaged` = `quantity`, set at
 * inspection; null until then.
 *
 * @property int $id
 * @property int $order_return_id
 * @property int $order_item_id
 * @property int $quantity
 * @property int|null $quantity_resellable
 * @property int|null $quantity_damaged
 * @property-read OrderItem $orderItem
 */
final class OrderReturnItem extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'order_return_id' => 'integer',
            'order_item_id' => 'integer',
            'quantity' => 'integer',
            'quantity_resellable' => 'integer',
            'quantity_damaged' => 'integer',
        ];
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
