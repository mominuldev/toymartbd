<?php

declare(strict_types=1);

namespace App\Domain\Content\Enums;

/** The storefront page a banner request is made for. Cart and checkout filter hard (rule 25b). */
enum BannerContext: string
{
    case General = 'general';
    case Cart = 'cart';
    case Checkout = 'checkout';

    public function isPurchaseFlow(): bool
    {
        return $this !== self::General;
    }
}
