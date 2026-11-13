<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * Where a recommendation was shown (DATABASE-DESIGN §3.1 `recommendation_events.placement`).
 * The placement that put a line in the cart travels on `cart_items.recommendation_source`
 * and is stamped onto `order_items.recommendation_source` at checkout (rule 25d), so the
 * P&L can attribute what each placement actually sold. There is deliberately no checkout
 * placement (rule 25b).
 */
enum RecommendationPlacement: string
{
    case PdpUpsell = 'pdp_upsell';
    case PdpCrossSell = 'pdp_cross_sell';
    case PdpFbt = 'pdp_fbt';
    case PdpAgeProgression = 'pdp_age_progression';
    case CartCrossSell = 'cart_cross_sell';
    case ConfirmationCrossSell = 'confirmation_cross_sell';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }
}
