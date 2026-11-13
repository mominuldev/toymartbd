<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Purchasing\Enums\LandedCostMethod;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One delivery received against a PO (as built, P5). Written once by ReceivePurchase;
 * its stock movements reference it (`reference_type = 'purchase_receipt'`).
 *
 * @property int $id
 * @property string|null $number
 * @property int $purchase_id
 * @property int $location_id
 * @property Carbon $received_at
 * @property Money $freight
 * @property Money $customs_duty
 * @property Money $other_cost
 * @property LandedCostMethod $apportion_method
 * @property bool $costs_paid_to_supplier
 * @property string|null $costs_vendor
 * @property string|null $costs_reference
 * @property string|null $costs_payment_method
 * @property Money $goods_value
 * @property Money $payable_amount
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Purchase $purchase
 * @property-read Collection<int, PurchaseReceiptItem> $items
 */
final class PurchaseReceipt extends Model
{
    /** `stock_movements.reference_type` for a receipt's purchase_in movements. */
    public const string REFERENCE_TYPE = 'purchase_receipt';

    /** @var list<string> */
    protected $fillable = [
        'number', 'purchase_id', 'location_id', 'received_at', 'freight', 'customs_duty', 'other_cost',
        'apportion_method', 'costs_paid_to_supplier', 'costs_vendor', 'costs_reference', 'costs_payment_method', 'goods_value', 'payable_amount', 'note', 'created_by',
    ];

    /** GRN-2026-0012 (goods received note). */
    public static function numberFor(int $id, Carbon $at): string
    {
        return sprintf('GRN-%s-%04d', $at->format('Y'), $id);
    }

    public function landedTotal(): Money
    {
        return $this->freight->plus($this->customs_duty)->plus($this->other_cost);
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** @return HasMany<PurchaseReceiptItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReceiptItem::class, 'receipt_id')->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purchase_id' => 'integer',
            'location_id' => 'integer',
            'created_by' => 'integer',
            'received_at' => 'datetime',
            'freight' => MoneyCast::class,
            'customs_duty' => MoneyCast::class,
            'other_cost' => MoneyCast::class,
            'apportion_method' => LandedCostMethod::class,
            'costs_paid_to_supplier' => 'boolean',
            'goods_value' => MoneyCast::class,
            'payable_amount' => MoneyCast::class,
        ];
    }
}
