<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Enums\InventoryAlertKind;
use App\Domain\Inventory\Enums\InventoryAlertStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One inventory alert episode (P5-INV): a low/out-of-stock level (StockAlerts) or a
 * ledger/reserved drift (ReconcileStockLevels). See InventoryAlertKind for what each
 * kind's `expected` / `actual` hold. `alert_date` is the Dhaka day it was raised.
 *
 * @property int $id
 * @property InventoryAlertKind $kind
 * @property InventoryAlertStatus $status
 * @property int $variant_id
 * @property int $location_id
 * @property int|null $expected
 * @property int|null $actual
 * @property Carbon $alert_date
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $resolved_at
 * @property int|null $acknowledged_by
 * @property Carbon|null $acknowledged_at
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProductVariant $variant
 * @property-read StockLocation $location
 * @property-read User|null $acknowledger
 */
final class InventoryAlert extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'kind', 'status', 'variant_id', 'location_id', 'expected', 'actual', 'alert_date',
        'first_seen_at', 'last_seen_at', 'resolved_at', 'acknowledged_by', 'acknowledged_at', 'note',
    ];

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', InventoryAlertStatus::Open->value);
    }

    /** Signed gap: what stock_levels holds minus what it should hold (drift kinds). */
    public function difference(): ?int
    {
        return $this->expected === null || $this->actual === null ? null : $this->actual - $this->expected;
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => InventoryAlertKind::class,
            'status' => InventoryAlertStatus::class,
            'variant_id' => 'integer',
            'location_id' => 'integer',
            'expected' => 'integer',
            'actual' => 'integer',
            'alert_date' => 'date',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'acknowledged_by' => 'integer',
            'acknowledged_at' => 'datetime',
        ];
    }
}
