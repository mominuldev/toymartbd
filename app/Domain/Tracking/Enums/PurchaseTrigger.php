<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Enums;

/**
 * Which order event fires the `purchase` conversion (PRD K-02). Exactly one of them, never
 * both: `order_placed` counts every order the moment it is placed (fast feedback for ad
 * optimisation, includes COD orders that later fail); `order_delivered` counts only orders
 * the customer accepted and paid for (truer in a COD market, but days late and server-only:
 * the browser is gone by then).
 */
enum PurchaseTrigger: string
{
    case OrderPlaced = 'order_placed';
    case OrderDelivered = 'order_delivered';
}
