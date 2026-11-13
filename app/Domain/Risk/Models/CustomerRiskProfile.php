<?php

declare(strict_types=1);

namespace App\Domain\Risk\Models;

use App\Domain\Risk\Enums\RiskProfileStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One phone's risk profile (DATABASE-DESIGN §3.3). The counts, `internal_score` and
 * `score_breakdown` are OUR projection of the phone's orders, rebuilt by
 * {@see \App\Domain\Risk\Actions\RefreshRiskProfile}; the `courier_*` columns are verified
 * external data with their own timestamp, never merged into our score (rule 24). `status`
 * is a staff decision ({@see \App\Domain\Risk\Actions\SetPhoneRiskStatus}).
 *
 * @property int $id
 * @property string $phone
 * @property int|null $customer_id
 * @property int $total_orders
 * @property int $delivered_count
 * @property int $cancelled_count
 * @property int $returned_count
 * @property int $failed_delivery_count
 * @property int $refused_count
 * @property int $internal_score
 * @property list<array{code: string, weight: int, value: string, contribution: int}>|null $score_breakdown
 * @property Carbon|null $score_computed_at
 * @property array<string, mixed>|null $courier_data
 * @property Carbon|null $courier_checked_at
 * @property int|null $courier_delivered_count
 * @property int|null $courier_cancelled_count
 * @property RiskProfileStatus $status
 * @property string|null $status_reason
 * @property int|null $status_set_by
 * @property Carbon|null $status_set_at
 * @property string|null $manual_note
 * @property Carbon|null $last_order_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CustomerRiskProfile extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'phone', 'customer_id', 'total_orders', 'delivered_count', 'cancelled_count', 'returned_count',
        'failed_delivery_count', 'refused_count', 'internal_score', 'score_breakdown', 'score_computed_at',
        'status', 'status_reason', 'status_set_by', 'status_set_at', 'manual_note', 'last_order_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'normal', 'internal_score' => 0];

    public static function forPhone(string $e164): ?self
    {
        return self::query()->where('phone', $e164)->first();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'total_orders' => 'integer',
            'delivered_count' => 'integer',
            'cancelled_count' => 'integer',
            'returned_count' => 'integer',
            'failed_delivery_count' => 'integer',
            'refused_count' => 'integer',
            'internal_score' => 'integer',
            'score_breakdown' => 'array',
            'score_computed_at' => 'datetime',
            'courier_data' => 'array',
            'courier_checked_at' => 'datetime',
            'courier_delivered_count' => 'integer',
            'courier_cancelled_count' => 'integer',
            'status' => RiskProfileStatus::class,
            'status_set_by' => 'integer',
            'status_set_at' => 'datetime',
            'last_order_at' => 'datetime',
        ];
    }
}
