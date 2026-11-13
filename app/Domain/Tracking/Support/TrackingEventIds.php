<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Support;

use Ramsey\Uuid\Uuid;

/**
 * Deterministic event ids for server-originated conversions. The browser and the server
 * must agree on a purchase's `event_id` without talking to each other (Meta deduplicates
 * on event_id + event_name), so it is a UUIDv5 of `purchase:{order_number}` under a fixed
 * namespace: the order confirmation resource hands the same value to the storefront.
 *
 * NEVER change {@see self::NAMESPACE}: every id ever sent would stop matching.
 */
final class TrackingEventIds
{
    /** Fixed, random namespace for Toy Mart BD tracking ids (generated 2026-11-13). */
    public const string NAMESPACE = '6f1c2a9e-3b7d-4e58-9a41-0c5d8e2f7b63';

    public static function purchase(string $orderNumber): string
    {
        return Uuid::uuid5(self::NAMESPACE, 'purchase:'.$orderNumber)->toString();
    }

    public static function refund(int $refundId): string
    {
        return Uuid::uuid5(self::NAMESPACE, 'refund:'.$refundId)->toString();
    }
}
