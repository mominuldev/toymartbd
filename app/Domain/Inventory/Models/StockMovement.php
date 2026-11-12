<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\StockLedgerViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One append-only stock ledger row (DATABASE-DESIGN §3.2, rule 8). Insert-only: saving
 * an existing row or deleting one throws, so no code path can rewrite history.
 *
 * @property int $id
 * @property int $variant_id
 * @property int $location_id
 * @property StockMovementType $type
 * @property int $quantity
 * @property int $balance_after
 * @property string|null $unit_cost
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $user_id
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property-read ProductVariant $variant
 */
final class StockMovement extends Model
{
    public const null UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'variant_id', 'location_id', 'type', 'quantity', 'balance_after', 'unit_cost',
        'reference_type', 'reference_id', 'user_id', 'note',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw StockLedgerViolation::appendOnly());
        self::deleting(static fn (): never => throw StockLedgerViolation::appendOnly());
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'variant_id' => 'integer',
            'location_id' => 'integer',
            'type' => StockMovementType::class,
            'quantity' => 'integer',
            'balance_after' => 'integer',
            'unit_cost' => 'decimal:4',
            'reference_id' => 'integer',
            'user_id' => 'integer',
        ];
    }
}
