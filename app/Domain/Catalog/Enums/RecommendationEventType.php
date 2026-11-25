<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * What happened to a recommendation (`recommendation_events.event`, rule 25d). The storefront
 * reports the first three; `purchased` is written by the server alone, when an order is placed
 * with a line whose `recommendation_source` is set.
 */
enum RecommendationEventType: string
{
    case Impression = 'impression';
    case Click = 'click';
    case AddToCart = 'add_to_cart';
    case Purchased = 'purchased';

    /**
     * The events a storefront may report — never `purchased`.
     *
     * @return list<string>
     */
    public static function clientValues(): array
    {
        return [self::Impression->value, self::Click->value, self::AddToCart->value];
    }
}
