<?php

declare(strict_types=1);

namespace App\Domain\Content\Enums;

/**
 * Banner slots outside the homepage blocks (the home hero is a CMS block).
 *
 * - announcement_bar: the strip above the header.
 * - category_page: under a category page's header; optionally targeted at one category.
 * - info_strip: a plain information line (delivery cut-off, holiday notice). The ONLY
 *   placement that may render on cart and checkout, and it is text-only there (rule 25b:
 *   checkout carries no upsell or cross-sell).
 * - popup: a dismissible notice; never on cart or checkout.
 */
enum BannerPlacement: string
{
    case AnnouncementBar = 'announcement_bar';
    case CategoryPage = 'category_page';
    case InfoStrip = 'info_strip';
    case Popup = 'popup';

    /** May this placement render on the cart or checkout page? */
    public function allowedOnCheckout(): bool
    {
        return $this === self::InfoStrip;
    }

    public function allowsImage(): bool
    {
        return in_array($this, [self::CategoryPage, self::Popup], true);
    }
}
