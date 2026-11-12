<?php

declare(strict_types=1);

namespace App\Domain\Search\Support;

use App\Domain\Catalog\DTOs\ProductListingFilters as F;
use App\Domain\Catalog\Enums\AgeBand;
use App\Domain\Catalog\Enums\Gender;
use App\Domain\Catalog\Enums\PriceBand;

/**
 * The listing filters as Meilisearch filter expressions, one group per facet, with the
 * same semantics as ProductListing::filtered() (see ProductDocumentBuilder for how each
 * field is stored). A facet's counts apply every group except its own (disjunctive
 * facets), exactly like the listing.
 */
final class FilterGroups
{
    /** Facet key (ProductListingFilters constants) → the index field its counts come from. */
    public const array FACET_FIELDS = [
        F::CATEGORY => 'category_slugs',
        F::BRAND => 'brand_slug',
        F::AGE => 'age_bands',
        F::PRICE => 'price_band',
        F::GENDER => 'gender',
        F::RATING => 'rating_floor',
        F::EDUCATIONAL => 'is_educational',
        self::AVAILABILITY => 'in_stock',
    ];

    /** The in-stock facet. Its filter arrives with P4-INV (ProductListingFilters::$inStock). */
    public const string AVAILABILITY = 'availability';

    /**
     * Each active group as a filter expression (an AND of its parts), keyed by facet.
     *
     * @return array<string, string>
     */
    public static function expressions(F $filters): array
    {
        $groups = [];

        if ($filters->category !== null) {
            $groups[F::CATEGORY] = 'category_slugs = '.self::quote($filters->category);
        }

        if ($filters->brands !== []) {
            $groups[F::BRAND] = 'brand_slug IN '.self::list($filters->brands);
        }

        $age = [];
        if ($filters->ageMonths !== null) {
            $age[] = "age_min <= {$filters->ageMonths} AND age_max >= {$filters->ageMonths}";
        }
        if ($filters->ageBands !== []) {
            $age[] = 'age_bands IN '.self::list(array_map(static fn (AgeBand $b): string => $b->value, $filters->ageBands));
        }
        if ($age !== []) {
            $groups[F::AGE] = self::and($age);
        }

        $price = [];
        if ($filters->priceMin !== null) {
            $price[] = 'price_min >= '.self::number($filters->priceMin);
        }
        if ($filters->priceMax !== null) {
            $price[] = 'price_min <= '.self::number($filters->priceMax);
        }
        if ($filters->priceBands !== []) {
            $price[] = 'price_band IN '.self::list(array_map(static fn (PriceBand $b): string => $b->value, $filters->priceBands));
        }
        if ($price !== []) {
            $groups[F::PRICE] = self::and($price);
        }

        if ($filters->genders !== []) {
            // `unisex` already covers "no gender": the document stores null as unisex.
            $groups[F::GENDER] = 'gender IN '.self::list(array_map(static fn (Gender $g): string => $g->value, $filters->genders));
        }

        if ($filters->minRating !== null) {
            $groups[F::RATING] = "rating >= {$filters->minRating}";
        }

        if ($filters->educational !== null) {
            $groups[F::EDUCATIONAL] = 'is_educational = '.($filters->educational ? 'true' : 'false');
        }

        $inStock = self::inStock($filters);
        if ($inStock !== null) {
            $groups[self::AVAILABILITY] = 'in_stock = '.($inStock ? 'true' : 'false');
        }

        return $groups;
    }

    /** The whole filter with one group left out (null = none left out). */
    public static function filter(F $filters, ?string $except = null): ?string
    {
        $groups = self::expressions($filters);
        unset($groups[$except ?? '']);

        return $groups === [] ? null : self::and(array_values($groups));
    }

    /**
     * The in-stock filter, once ProductListingFilters carries it (agent work in P4-INV
     * adds `inStock`); null until then and whenever it is unset.
     */
    public static function inStock(F $filters): ?bool
    {
        $value = get_object_vars($filters)['inStock'] ?? null;

        return is_bool($value) ? $value : null;
    }

    /** @param  list<string>  $parts */
    private static function and(array $parts): string
    {
        return count($parts) === 1 ? $parts[0] : '('.implode(') AND (', $parts).')';
    }

    private static function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /** @param  list<string>  $values */
    private static function list(array $values): string
    {
        return '['.implode(', ', array_map(self::quote(...), $values)).']';
    }

    /** A validated decimal string ("1000.00") as a filter number. */
    private static function number(string $decimal): string
    {
        return preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $decimal) === 1 ? $decimal : '0';
    }
}
