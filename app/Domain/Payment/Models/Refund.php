<?php

declare(strict_types=1);

namespace App\Domain\Payment\Models;

use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Enums\RefundType;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A refund against an order (DATABASE-DESIGN §3.5). Written by the return/refund Actions
 * in App\Domain\Ordering\Actions\Returns (§4.7: approval threshold, restock, ledger,
 * audit_sensitive). `amount` includes `shipping_amount` (the delivery-charge part).
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $payment_id
 * @property Money $amount
 * @property Money $shipping_amount
 * @property string $reason
 * @property RefundType $type
 * @property PaymentMethod $method
 * @property RefundStatus $status
 * @property string|null $gateway_reference
 * @property bool $restock
 * @property int|null $requested_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $processed_at
 * @property string|null $note
 */
final class Refund extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'order_id', 'payment_id', 'amount', 'shipping_amount', 'reason', 'type', 'method', 'status', 'gateway_reference',
        'restock', 'requested_by', 'approved_by', 'approved_at', 'processed_at', 'note',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'payment_id' => 'integer',
            'amount' => MoneyCast::class,
            'shipping_amount' => MoneyCast::class,
            'requested_by' => 'integer',
            'approved_by' => 'integer',
            'type' => RefundType::class,
            'method' => PaymentMethod::class,
            'status' => RefundStatus::class,
            'restock' => 'boolean',
            'approved_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return HasMany<RefundItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }
}
