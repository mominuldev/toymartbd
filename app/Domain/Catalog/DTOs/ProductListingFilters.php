<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs;

use App\Domain\Catalog\Enums\AgeBand;
use App\Domain\Catalog\Enums\Gender;
use App\Domain\Catalog\Enums\PriceBand;

/**
 * Parsed, validated storefront listing filters (P4-CAT-13). Built by the HTTP
 * layer from `filter[...]`; every list is de-duplicated. Money bounds are decimal
 * strings ("1000.00").
 *
 * `inStock` true lists only products with an active variant whose available stock
 * (on_hand − reserved) is above zero; null lists everything (sold-out cards show
 * their out-of-stock state).
 */
final readonly class ProductListingFilters
{
    /** Facet keys, as `except` arguments to ProductListing. */
    public const string CATEGORY = 'category';

    public const string BRAND = 'brand';

    public const string AGE = 'age';

    public const string PRICE = 'price';

    public const string GENDER = 'gender';

    public const string RATING = 'rating';

    public const string EDUCATIONAL = 'educational';

    public const string AVAILABILITY = 'availability';

    /**
     * @param  list<string>  $brands  brand slugs (any of)
     * @param  list<AgeBand>  $ageBands  any of
     * @param  list<PriceBand>  $priceBands  any of
     * @param  list<Gender>  $genders  any of; `unisex` also matches products with no gender
     */
    public function __construct(
        public ?string $category = null,
        public array $brands = [],
        public ?int $ageMonths = null,
        public array $ageBands = [],
        public ?string $priceMin = null,
        public ?string $priceMax = null,
        public array $priceBands = [],
        public array $genders = [],
        public ?int $minRating = null,
        public ?bool $educational = null,
        public ?bool $inStock = null,
    ) {}
}
