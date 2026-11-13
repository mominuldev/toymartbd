<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\SettlementReview;
use App\Domain\Finance\Enums\SettlementVariance;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One settlement line (§3.6): what the courier reported beside what our shipment expected.
 *
 * @property int $id
 * @property int $settlement_id
 * @property int|null $line_no
 * @property string|null $consignment_id
 * @property int|null $shipment_id
 * @property int|null $order_id
 * @property Money $cod_amount
 * @property Money $charges
 * @property Money $cod_fee
 * @property Money $return_charge
 * @property Money $net
 * @property Money|null $expected_cod
 * @property Money|null $expected_net
 * @property Money $variance
 * @property SettlementVariance $variance_type
 * @property bool $is_matched
 * @property SettlementReview $review_status
 * @property string|null $review_note
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $booked_at
 */
final class CourierSettlementItem extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'settlement_id', 'line_no', 'consignment_id', 'shipment_id', 'order_id', 'cod_amount', 'charges', 'cod_fee',
        'return_charge', 'net', 'expected_cod', 'expected_net', 'variance', 'variance_type', 'is_matched',
        'review_status', 'review_note', 'reviewed_by', 'reviewed_at', 'booked_at',
    ];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('A settlement line is never deleted.'));
    }

    /** @return BelongsTo<CourierSettlement, $this> */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CourierSettlement::class, 'settlement_id');
    }

    /** Booked on confirm: a clean match, or a short payment a person accepted. */
    public function isBookable(): bool
    {
        return $this->shipment_id !== null && $this->order_id !== null && $this->booked_at === null
            && $this->review_status !== SettlementReview::Excluded
            && ($this->variance_type === SettlementVariance::None || $this->review_status === SettlementReview::Accepted);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'settlement_id' => 'integer',
            'line_no' => 'integer',
            'shipment_id' => 'integer',
            'order_id' => 'integer',
            'cod_amount' => MoneyCast::class,
            'charges' => MoneyCast::class,
            'cod_fee' => MoneyCast::class,
            'return_charge' => MoneyCast::class,
            'net' => MoneyCast::class,
            'expected_cod' => MoneyCast::class,
            'expected_net' => MoneyCast::class,
            'variance' => MoneyCast::class,
            'variance_type' => SettlementVariance::class,
            'is_matched' => 'boolean',
            'review_status' => SettlementReview::class,
            'reviewed_by' => 'integer',
            'reviewed_at' => 'datetime',
            'booked_at' => 'datetime',
        ];
    }
}
