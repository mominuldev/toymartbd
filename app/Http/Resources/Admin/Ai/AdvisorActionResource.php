<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Ai;

use App\Domain\Ai\Advisor\Models\AiBriefAction;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One Growth advisor suggestion. `link.path` is an admin SPA path built by the server from
 * the target the AI named — never a URL the model wrote.
 *
 * @property AiBriefAction $resource
 */
#[SchemaName('AdvisorAction')]
final class AdvisorActionResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $a = $this->resource;
        $path = $a->path();

        return [
            'id' => $a->id,
            /** @var 'high'|'medium'|'low' */
            'priority' => $a->priority->value,
            /** @var 'restock'|'campaign'|'verify_orders'|'bangla_copy'|'other' */
            'kind' => $a->kind->value,
            'title' => $a->title,
            'why' => $a->why,
            /**
             * Short figure labels from the facts, e.g. "12 in stock".
             *
             * @var list<string>
             */
            'figures' => $a->figures,
            /**
             * Where the primary button goes; null when there is nothing to open.
             *
             * @var array{type: 'product'|'restock_forecast'|'purchase_order_new'|'low_stock'|'verification_queue'|'failed_bookings'|'promotion'|'promotions'|'coupons'|'sales_report', path: string}|null
             */
            'link' => $path === null ? null : ['type' => $a->target_type->value, 'path' => $path],
            /** @var 'open'|'done'|'dismissed' */
            'status' => $a->status->value,
            /** ISO 8601, when it was marked done or not useful. */
            'decided_at' => $a->decided_at?->toIso8601String(),
        ];
    }
}
