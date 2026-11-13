<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A cached delivery-history lookup from a courier's OFFICIAL endpoint (DATABASE-DESIGN
 * §3.6, rules 24, 24a, 33b). Verified external data with its retrieval time — shown
 * beside our own risk score, never blended into it.
 *
 * @property int $id
 * @property int $provider_id
 * @property string $phone
 * @property int|null $total_parcels
 * @property int|null $total_delivered
 * @property int|null $total_cancelled
 * @property int|null $total_fraud_reports
 * @property string|null $delivery_ratio
 * @property string|null $cancellation_ratio
 * @property array<string, mixed>|null $raw
 * @property string $source
 * @property Carbon $fetched_at
 * @property-read CourierProvider $provider
 */
final class CourierFraudLookup extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'provider_id', 'phone', 'total_parcels', 'total_delivered', 'total_cancelled', 'total_fraud_reports',
        'delivery_ratio', 'cancellation_ratio', 'raw', 'source', 'fetched_at',
    ];

    /** @return BelongsTo<CourierProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(CourierProvider::class, 'provider_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'total_parcels' => 'integer',
            'total_delivered' => 'integer',
            'total_cancelled' => 'integer',
            'total_fraud_reports' => 'integer',
            'delivery_ratio' => 'decimal:4',
            'cancellation_ratio' => 'decimal:4',
            'raw' => 'array',
            'fetched_at' => 'datetime',
        ];
    }
}
