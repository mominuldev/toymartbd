<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A purchase order (DATABASE-DESIGN §3.2). Written only by the Purchasing actions; never
 * deleted. Totals are maintained by the actions, never computed by a client (rule 1).
 *
 * @property int $id
 * @property string|null $number
 * @property int $supplier_id
 * @property int $location_id
 * @property PurchaseStatus $status
 * @property Carbon $order_date
 * @property Carbon|null $expected_date
 * @property Carbon|null $ordered_at
 * @property Carbon|null $received_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $closed_at
 * @property int|null $closed_by
 * @property string|null $close_reason
 * @property Money $subtotal
 * @property Money $discount
 * @property Money $shipping_cost
 * @property Money $customs_duty
 * @property Money $other_cost
 * @property Money $tax
 * @property Money $total
 * @property Money $received_value
 * @property Money $paid_amount
 * @property Money $due_amount
 * @property string|null $supplier_invoice_no
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Supplier $supplier
 * @property-read Collection<int, PurchaseItem> $items
 * @property-read Collection<int, PurchaseReceipt> $receipts
 */
final class Purchase extends Model
{
    /** `stock_movements.reference_type` is the receipt, not the PO: see PurchaseReceipt. */
    public const string SOURCE_TYPE = 'purchase';

    /** @var list<string> */
    protected $fillable = [
        'number', 'supplier_id', 'location_id', 'status', 'order_date', 'expected_date', 'ordered_at',
        'received_at', 'cancelled_at', 'closed_at', 'closed_by', 'close_reason', 'subtotal', 'discount', 'shipping_cost', 'customs_duty', 'other_cost',
        'tax', 'total', 'received_value', 'paid_amount', 'due_amount', 'supplier_invoice_no', 'note', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'subtotal' => '0.00',
        'discount' => '0.00',
        'shipping_cost' => '0.00',
        'customs_duty' => '0.00',
        'other_cost' => '0.00',
        'tax' => '0.00',
        'total' => '0.00',
        'received_value' => '0.00',
        'paid_amount' => '0.00',
        'due_amount' => '0.00',
    ];

    /** PO-2026-0044 — the year it was raised and the id, so numbers never collide. */
    public static function numberFor(int $id, Carbon $at): string
    {
        return sprintf('PO-%s-%04d', $at->format('Y'), $id);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return HasMany<PurchaseItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<PurchaseReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class)->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'location_id' => 'integer',
            'created_by' => 'integer',
            'closed_by' => 'integer',
            'closed_at' => 'datetime',
            'status' => PurchaseStatus::class,
            'order_date' => 'date',
            'expected_date' => 'date',
            'ordered_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'subtotal' => MoneyCast::class,
            'discount' => MoneyCast::class,
            'shipping_cost' => MoneyCast::class,
            'customs_duty' => MoneyCast::class,
            'other_cost' => MoneyCast::class,
            'tax' => MoneyCast::class,
            'total' => MoneyCast::class,
            'received_value' => MoneyCast::class,
            'paid_amount' => MoneyCast::class,
            'due_amount' => MoneyCast::class,
        ];
    }
}
