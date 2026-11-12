<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs;

/**
 * Where a product is sold (Figma "Status & visibility" 241:37942). Stored flags only:
 * the storefront, POS and the feeds read them when those channels are built.
 */
final readonly class SalesChannelsData
{
    public function __construct(
        public bool $onlineStore = true,
        public bool $pos = true,
        public bool $facebookInstagram = false,
        public bool $googleMerchant = false,
    ) {}
}
