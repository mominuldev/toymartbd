<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\SettlementReview;
use App\Domain\Finance\Enums\SettlementStatus;
use App\Domain\Finance\Enums\SettlementVariance;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One courier COD remittance (§3.6, §4.5). Never deleted; a wrong import is left unconfirmed.
 *
 * @property int $id
 * @property int $provider_id
 * @property string $reference
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Money $cod_collected
 * @property Money $delivery_charges
 * @property Money $cod_fees
 * @property Money $return_charges
 * @property Money $adjustments
 * @property Money $net_received
 * @property Carbon $received_at
 * @property SettlementStatus $status
 * @property string $source
 * @property int $variance_count
 * @property string|null $statement_path
 * @property string|null $note
 * @property int|null $imported_by
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CourierSettlement extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'provider_id', 'reference', 'period_start', 'period_end', 'cod_collected', 'delivery_charges', 'cod_fees',
        'return_charges', 'adjustments', 'net_received', 'received_at', 'status', 'source', 'variance_count',
        'statement_path', 'note', 'imported_by', 'confirmed_by', 'confirmed_at',
    ];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('A courier settlement is never deleted.'));
    }

    /** @return HasMany<CourierSettlementItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CourierSettlementItem::class, 'settlement_id');
    }

    /** Variance lines nobody has decided on yet — the settlement reads "disputed" while any remain. */
    public function unresolvedCount(): int
    {
        return $this->items()->where('variance_type', '!=', SettlementVariance::None->value)
            ->where('review_status', SettlementReview::Pending->value)->count();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider_id' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'cod_collected' => MoneyCast::class,
            'delivery_charges' => MoneyCast::class,
            'cod_fees' => MoneyCast::class,
            'return_charges' => MoneyCast::class,
            'adjustments' => MoneyCast::class,
            'net_received' => MoneyCast::class,
            'received_at' => 'date',
            'status' => SettlementStatus::class,
            'variance_count' => 'integer',
            'imported_by' => 'integer',
            'confirmed_by' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }
}
