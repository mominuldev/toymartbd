<?php

declare(strict_types=1);

namespace App\Domain\Search\Enums;

/**
 * Search result orders (`sort=`): relevance first (storefront spec B6.1: "Value=Relevance
 * by default here"), then the listing's sorts with the same names and meaning.
 */
enum SearchSort: string
{
    case Relevance = 'relevance';
    case Featured = 'featured';
    case Newest = 'newest';
    case PriceAsc = 'price_asc';
    case PriceDesc = 'price_desc';
    case Rating = 'rating';
    case Name = 'name';

    /**
     * Meilisearch `sort` (empty = pure relevance). Ties fall back to the ranking rules.
     *
     * @return list<string>
     */
    public function meilisearch(): array
    {
        return match ($this) {
            self::Relevance => [],
            self::Featured => ['is_featured:desc', 'published_at:desc'],
            self::Newest => ['published_at:desc'],
            self::PriceAsc => ['price_min:asc'],
            self::PriceDesc => ['price_min:desc'],
            self::Rating => ['rating:desc', 'rating_count:desc'],
            self::Name => ['name:asc'],
        };
    }
}
