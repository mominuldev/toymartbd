<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\RecommendationEventType;
use App\Domain\Catalog\Enums\RecommendationPlacement;
use App\Domain\Catalog\Exceptions\RecommendationLogViolation;
use Database\Factories\RecommendationEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One recommendation impression, click, add-to-cart or purchase (DATABASE-DESIGN §3.1, rule 25d).
 * Append-only: saving an existing row or deleting one throws. Bulk writes from
 * {@see \App\Domain\Catalog\Jobs\RecordRecommendationEvents} go through the query builder
 * (insert only), which no update/delete path can reach either.
 *
 * @property int $id
 * @property RecommendationPlacement $placement
 * @property RecommendationEventType $event
 * @property int|null $source_product_id
 * @property int $recommended_product_id
 * @property int|null $variant_id
 * @property string|null $session_id
 * @property int|null $customer_id
 * @property int|null $order_id
 * @property Carbon $occurred_at
 */
final class RecommendationEvent extends Model
{
    /** @use HasFactory<RecommendationEventFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'placement', 'event', 'source_product_id', 'recommended_product_id', 'variant_id',
        'session_id', 'customer_id', 'order_id', 'occurred_at',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw RecommendationLogViolation::appendOnly());
        self::deleting(static fn (): never => throw RecommendationLogViolation::appendOnly());
    }

    protected static function newFactory(): RecommendationEventFactory
    {
        return RecommendationEventFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'placement' => RecommendationPlacement::class,
            'event' => RecommendationEventType::class,
            'source_product_id' => 'integer',
            'recommended_product_id' => 'integer',
            'variant_id' => 'integer',
            'customer_id' => 'integer',
            'order_id' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }
}
