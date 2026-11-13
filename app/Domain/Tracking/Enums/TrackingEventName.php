<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Enums;

/**
 * The commerce events we track. The backing value is GA4's recommended event name.
 *
 * GA4 names: https://developers.google.com/analytics/devguides/collection/ga4/reference/events
 * Meta standard events: https://developers.facebook.com/docs/meta-pixel/reference#standard-events
 */
enum TrackingEventName: string
{
    case PageView = 'page_view';
    case ViewItem = 'view_item';
    case AddToCart = 'add_to_cart';
    case BeginCheckout = 'begin_checkout';
    case Purchase = 'purchase';
    case Refund = 'refund';

    public function ga4Name(): string
    {
        return $this->value;
    }

    /**
     * Meta standard-event name, or null when the event is not sent to Meta at all.
     * Meta has no standard refund event, so a refund is never sent there.
     */
    public function metaName(): ?string
    {
        return match ($this) {
            self::PageView => 'PageView',
            self::ViewItem => 'ViewContent',
            self::AddToCart => 'AddToCart',
            self::BeginCheckout => 'InitiateCheckout',
            self::Purchase => 'Purchase',
            self::Refund => null,
        };
    }
}
