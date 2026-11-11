<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/** Storefront listing sorts (`sort=`). Each ends on `id` so cursor pagination is stable. */
enum ProductSort: string
{
    case Featured = 'featured';
    case Newest = 'newest';
    case PriceAsc = 'price_asc';
    case PriceDesc = 'price_desc';
    case Rating = 'rating';
    case Name = 'name';

    /**
     * Columns of the listing's derived table, in order, with their direction.
     *
     * @return list<array{0: string, 1: 'asc'|'desc'}>
     */
    public function orders(): array
    {
        return match ($this) {
            self::Featured => [['is_featured', 'desc'], ['published_at', 'desc'], ['id', 'desc']],
            self::Newest => [['published_at', 'desc'], ['id', 'desc']],
            self::PriceAsc => [['listing_price_min', 'asc'], ['id', 'asc']],
            self::PriceDesc => [['listing_price_min', 'desc'], ['id', 'desc']],
            self::Rating => [['listing_rating', 'desc'], ['rating_count', 'desc'], ['id', 'desc']],
            self::Name => [['name', 'asc'], ['id', 'asc']],
        };
    }
}
