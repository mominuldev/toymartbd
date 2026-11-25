<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Recommendations;

use App\Domain\Catalog\Enums\RecommendationEventType;
use App\Domain\Catalog\Enums\RecommendationPlacement;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /storefront/recommendation-events` (docs/api/recommendations.md, rule 25d): a batch of
 * 1–50 recommendation impressions, clicks and add-to-carts from the storefront. `purchased` is
 * never accepted from a client — the server writes it when an order is placed. Ids are JSON
 * integers (a numeric string is a 422). `source_product_id` is required on the product-page
 * placements and may be null on the cart and confirmation rails.
 */
final class StoreRecommendationEventsRequest extends ApiFormRequest
{
    public const int MAX_EVENTS = 50;

    /** Placements with no source product: the rail is keyed by a cart or an order. */
    public const array SOURCELESS_PLACEMENTS = [
        RecommendationPlacement::CartCrossSell,
        RecommendationPlacement::ConfirmationCrossSell,
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $sourceless = array_map(static fn (RecommendationPlacement $p): string => $p->value, self::SOURCELESS_PLACEMENTS);

        return [
            /** Opaque storefront session id (the `tm_rsid` cookie), 16–64 of `[A-Za-z0-9_-]`. */
            'session_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
            'events' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_EVENTS],
            'events.*' => ['required', 'array:placement,event,source_product_id,recommended_product_id,variant_id'],
            /** @var 'pdp_upsell'|'pdp_cross_sell'|'pdp_fbt'|'pdp_age_progression'|'cart_cross_sell'|'confirmation_cross_sell' */
            'events.*.placement' => ['required', 'string', Rule::in(RecommendationPlacement::values())],
            /** @var 'impression'|'click'|'add_to_cart' */
            'events.*.event' => ['required', 'string', Rule::in(RecommendationEventType::clientValues())],
            'events.*.source_product_id' => ['nullable', 'required_unless:events.*.placement,'.implode(',', $sourceless), 'integer:strict', 'min:1'],
            'events.*.recommended_product_id' => ['required', 'integer:strict', 'min:1'],
            'events.*.variant_id' => ['nullable', 'integer:strict', 'min:1'],
        ];
    }

    /**
     * The validated events as `recommendation_events` rows (without `occurred_at`/`customer_id`).
     *
     * @return list<array{placement: string, event: string, source_product_id: int|null, recommended_product_id: int, variant_id: int|null, session_id: string}>
     */
    public function rows(): array
    {
        $session = $this->string('session_id')->toString();
        /** @var list<array<string, mixed>> $events */
        $events = $this->validated('events');

        return array_map(static fn (array $e): array => [
            'placement' => (string) $e['placement'],
            'event' => (string) $e['event'],
            'source_product_id' => isset($e['source_product_id']) ? (int) $e['source_product_id'] : null,
            'recommended_product_id' => (int) $e['recommended_product_id'],
            'variant_id' => isset($e['variant_id']) ? (int) $e['variant_id'] : null,
            'session_id' => $session,
        ], $events);
    }
}
