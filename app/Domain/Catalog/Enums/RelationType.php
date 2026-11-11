<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * `product_relations.type` (DATABASE-DESIGN §3.1). `age_progression` is its own type,
 * not a flavour of upsell: an upsell is a better version of the same toy; an age
 * progression is the next toy, for a child who has outgrown this one.
 */
enum RelationType: string
{
    case Related = 'related';
    case Upsell = 'upsell';
    case CrossSell = 'cross_sell';
    case Bundle = 'bundle';
    case AgeProgression = 'age_progression';
}
