<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Enums;

/** What a storefront contact-form message is about (Contact page, owner decision 2026-11-16). */
enum ContactTopic: string
{
    case ChoosingToy = 'choosing_toy';
    case Order = 'order';
    case Delivery = 'delivery';
    case Returns = 'returns';
    case GiftsBulk = 'gifts_bulk';
    case Other = 'other';

    /** The staff-facing label written into the inbox message (English; staff read both). */
    public function label(): string
    {
        return match ($this) {
            self::ChoosingToy => 'Help choosing a toy',
            self::Order => 'An order',
            self::Delivery => 'Delivery',
            self::Returns => 'Returns & refunds',
            self::GiftsBulk => 'Gifts & bulk orders',
            self::Other => 'Something else',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
