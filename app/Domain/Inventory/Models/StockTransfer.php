<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A move of stock between two locations (P5-INV). Written only by TransferStock; the
 * units move through the ledger (`transfer_out` at the source, `transfer_in` at the
 * destination), referenced back here.
 *
 * @property int $id
 * @property string|null $number
 * @property int $from_location_id
 * @property int $to_location_id
 * @property StockTransferStatus $status
 * @property string|null $note
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, StockTransferItem> $items
 * @property-read StockLocation $fromLocation
 * @property-read StockLocation $toLocation
 * @property-read User|null $user
 */
final class StockTransfer extends Model
{
    /** `stock_movements.reference_type` for a transfer's movements. */
    public const string REFERENCE_TYPE = 'stock_transfer';

    /** @var list<string> */
    protected $fillable = ['number', 'from_location_id', 'to_location_id', 'status', 'note', 'user_id'];

    /** TRF-2026-0007 — the year it was made and the id, so numbers never collide. */
    public static function numberFor(int $id, Carbon $at): string
    {
        return sprintf('TRF-%s-%04d', $at->format('Y'), $id);
    }

    /** @return HasMany<StockTransferItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class, 'transfer_id');
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'from_location_id');
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'to_location_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_location_id' => 'integer',
            'to_location_id' => 'integer',
            'user_id' => 'integer',
            'status' => StockTransferStatus::class,
        ];
    }
}
