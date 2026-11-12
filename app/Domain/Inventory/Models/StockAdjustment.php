<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\StockAdjustmentReason;
use App\Domain\Inventory\Enums\StockAdjustmentStatus;
use App\Models\User;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A manual stock change and its reason (DATABASE-DESIGN §3.2). Written only by
 * AdjustStock, ApproveStockAdjustment and RejectStockAdjustment; the stock itself moves
 * through the ledger, referenced back here, and only once the adjustment is applied or
 * approved (StockAdjustmentStatus).
 *
 * @property int $id
 * @property string|null $number
 * @property int $location_id
 * @property StockAdjustmentReason $reason
 * @property string|null $note
 * @property int|null $user_id
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $rejected_by
 * @property Carbon|null $rejected_at
 * @property string|null $decision_note
 * @property StockAdjustmentStatus $status
 * @property int $requested_units
 * @property Money $requested_value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, StockAdjustmentItem> $items
 */
final class StockAdjustment extends Model
{
    /** `stock_movements.reference_type` for an adjustment's movements. */
    public const string REFERENCE_TYPE = 'stock_adjustment';

    /** @var list<string> */
    protected $fillable = [
        'number', 'location_id', 'reason', 'note', 'user_id', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at',
        'decision_note', 'status', 'requested_units', 'requested_value',
    ];

    /** ADJ-2026-0012 — the year it was made and the id, so numbers never collide. */
    public static function numberFor(int $id, Carbon $at): string
    {
        return sprintf('ADJ-%s-%04d', $at->format('Y'), $id);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }

    /** @return HasMany<StockAdjustmentItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class, 'adjustment_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'user_id' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'rejected_by' => 'integer',
            'rejected_at' => 'datetime',
            'requested_units' => 'integer',
            'requested_value' => MoneyCast::class,
            'reason' => StockAdjustmentReason::class,
            'status' => StockAdjustmentStatus::class,
        ];
    }
}
