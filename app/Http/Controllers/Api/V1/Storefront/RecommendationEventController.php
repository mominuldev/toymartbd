<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Catalog\Jobs\RecordRecommendationEvents;
use App\Domain\Customer\Models\Customer;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\Recommendations\StoreRecommendationEventsRequest;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Response;

/**
 * Recommendation funnel events from the storefront (P10, rule 25d, docs/api/recommendations.md).
 * Called by the Next.js route handler `POST /api/recommendation-events`, with the storefront
 * server token — or the signed-in shopper's customer token, in which case `customer_id` is set.
 */
final class RecommendationEventController extends ApiController
{
    /**
     * `POST /storefront/recommendation-events` — queue a batch of 1–50 impressions, clicks and
     * add-to-carts for recording (queue `index`). `purchased` is server-written only. Always an
     * empty `202`: nothing is written inline.
     */
    #[OpenApiResponse(202, 'Accepted for recording; empty body')]
    #[OpenApiResponse(422, 'Invalid field, an unknown placement, a `purchased` event, or a non-integer id (`validation-error`)')]
    #[OpenApiResponse(429, 'Too many event batches for this session or shopper (`too-many-requests`)')]
    public function store(StoreRecommendationEventsRequest $request): Response
    {
        $user = $request->user();
        $customerId = $user instanceof Customer ? (int) $user->getKey() : null;
        $occurredAt = now('UTC')->toDateTimeString();

        $rows = array_map(static fn (array $row): array => [
            ...$row,
            'customer_id' => $customerId,
            'order_id' => null,
            'occurred_at' => $occurredAt,
        ], $request->rows());

        RecordRecommendationEvents::dispatch($rows);

        return response()->noContent(202);
    }
}
