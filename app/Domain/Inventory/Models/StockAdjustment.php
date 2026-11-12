<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\StockAdjustmentReason;
use App\Domain\Inventory\Enums\StockAdjustmentStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A manual stock change and its reason (DATABASE-DESIGN §3.2). Written only by
 * AdjustStock; the stock itself moves through the ledger, referenced back here.
 *
 * @property int $id
 * @property string|null $number
 * @property int $location_id
 * @property StockAdjustmentReason $reason
 * @property string|null $note
 * @property int|null $user_id
 * @property int|null $approved_by
 * @property StockAdjustmentStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, StockAdjustmentItem> $items
 */
final class StockAdjustment extends Model
{
    /** `stock_movements.reference_type` for an adjustment's movements. */
    public const string REFERENCE_TYPE = 'stock_adjustment';

    /** @var list<string> */
    protected $fillable = ['number', 'location_id', 'reason', 'note', 'user_id', 'approved_by', 'status'];

    /** ADJ-2026-0012 — the year it was made and the id, so numbers never collide. */
    public static function numberFor(int $id, Carbon $at): string
    {
        return sprintf('ADJ-%s-%04d', $at->format('Y'), $id);
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
            'reason' => StockAdjustmentReason::class,
            'status' => StockAdjustmentStatus::class,
        ];
    }
}
