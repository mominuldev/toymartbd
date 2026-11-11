<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront;

use App\Domain\Catalog\DTOs\ProductListingFilters;
use App\Domain\Catalog\Enums\AgeBand;
use App\Domain\Catalog\Enums\Gender;
use App\Domain\Catalog\Enums\PriceBand;
use App\Domain\Catalog\Enums\ProductSort;
use BackedEnum;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Parses `GET /storefront/products` query parameters against an explicit
 * allowlist (CLAUDE.md rule 16). An unlisted parameter, filter or sort, or a
 * malformed value, is a 400 `bad-request` — the same as the admin lists. A
 * well-formed slug that matches nothing (an archived brand in a shared link) is
 * not an error: it simply matches no products.
 *
 * Lists are comma-separated (`filter[brand]=lego,hape`).
 */
final class ProductListingQuery
{
    /** @var list<string> */
    public const array PARAMETERS = ['filter', 'sort', 'cursor'];

    /** @var list<string> */
    public const array FILTERS = [
        'category', 'brand', 'age', 'age_band', 'price_min', 'price_max', 'price_band', 'gender', 'rating', 'educational',
    ];

    public const int MAX_LIST = 20;

    /** 18 years: the catalogue's age data never goes higher. */
    public const int MAX_AGE_MONTHS = 216;

    private const string SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private const string DECIMAL = '/^\d{1,12}(?:\.\d{1,2})?$/';

    private function __construct(
        public readonly ProductListingFilters $filters,
        public readonly ProductSort $sort,
        public readonly ?string $cursor,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $query = $request->query->all();

        $unknown = array_diff(array_keys($query), self::PARAMETERS);
        if ($unknown !== []) {
            throw new BadRequestHttpException('Unknown query parameter(s): '.implode(', ', $unknown).'. Allowed: '.implode(', ', self::PARAMETERS).'.');
        }

        $filter = $query['filter'] ?? [];
        if (! is_array($filter)) {
            throw new BadRequestHttpException('filter must be an object: filter[name]=value.');
        }

        $unlisted = array_diff(array_map(strval(...), array_keys($filter)), self::FILTERS);
        if ($unlisted !== []) {
            throw new BadRequestHttpException('Unlisted filter(s): '.implode(', ', $unlisted).'. Allowed: '.implode(', ', self::FILTERS).'.');
        }

        $sort = self::sort($query['sort'] ?? null);

        $cursor = $query['cursor'] ?? null;
        if ($cursor !== null && (! is_string($cursor) || $cursor === '' || strlen($cursor) > 1000)) {
            throw new BadRequestHttpException('cursor must be the opaque string from meta.next_cursor.');
        }

        $priceMin = self::decimal($filter, 'price_min');
        $priceMax = self::decimal($filter, 'price_max');
        if ($priceMin !== null && $priceMax !== null && bccomp($priceMin, $priceMax, 2) > 0) {
            throw new BadRequestHttpException('filter[price_min] must not exceed filter[price_max].');
        }

        $category = self::scalar($filter, 'category');
        if ($category !== null && preg_match(self::SLUG, $category) !== 1) {
            throw new BadRequestHttpException('filter[category] must be a category slug.');
        }

        $brands = self::list($filter, 'brand');
        foreach ($brands as $slug) {
            if (preg_match(self::SLUG, $slug) !== 1) {
                throw new BadRequestHttpException('filter[brand] must be a comma-separated list of brand slugs.');
            }
        }

        return new self(
            new ProductListingFilters(
                category: $category,
                brands: $brands,
                ageMonths: self::integer($filter, 'age', 0, self::MAX_AGE_MONTHS),
                ageBands: self::enums($filter, 'age_band', AgeBand::class),
                priceMin: $priceMin,
                priceMax: $priceMax,
                priceBands: self::enums($filter, 'price_band', PriceBand::class),
                genders: self::enums($filter, 'gender', Gender::class),
                minRating: self::integer($filter, 'rating', 1, 5),
                educational: self::boolean($filter, 'educational'),
            ),
            $sort,
            $cursor,
        );
    }

    private static function sort(mixed $value): ProductSort
    {
        if ($value === null) {
            return ProductSort::Featured;
        }

        $sort = is_string($value) ? ProductSort::tryFrom($value) : null;
        if ($sort === null) {
            $allowed = implode(', ', array_map(static fn (ProductSort $s): string => $s->value, ProductSort::cases()));
            throw new BadRequestHttpException("sort must be one of: {$allowed}.");
        }

        return $sort;
    }

    /** @param  array<array-key, mixed>  $filter */
    private static function scalar(array $filter, string $name): ?string
    {
        if (! array_key_exists($name, $filter)) {
            return null;
        }

        $value = $filter[$name];
        if (! is_string($value) || trim($value) === '') {
            throw new BadRequestHttpException("filter[{$name}] must be a non-empty string.");
        }

        return trim($value);
    }

    /**
     * @param  array<array-key, mixed>  $filter
     * @return list<string>
     */
    private static function list(array $filter, string $name): array
    {
        $value = self::scalar($filter, $name);
        if ($value === null) {
            return [];
        }

        $items = array_values(array_unique(array_filter(array_map(trim(...), explode(',', $value)), static fn (string $s): bool => $s !== '')));
        if ($items === [] || count($items) > self::MAX_LIST) {
            throw new BadRequestHttpException("filter[{$name}] must list 1 to ".self::MAX_LIST.' values.');
        }

        return $items;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  array<array-key, mixed>  $filter
     * @param  class-string<T>  $enum
     * @return list<T>
     */
    private static function enums(array $filter, string $name, string $enum): array
    {
        $out = [];
        foreach (self::list($filter, $name) as $item) {
            $case = $enum::tryFrom($item);
            if ($case === null) {
                $allowed = implode(', ', array_map(static fn (BackedEnum $c): string => (string) $c->value, $enum::cases()));
                throw new BadRequestHttpException("filter[{$name}] must be a comma-separated list of: {$allowed}.");
            }
            $out[] = $case;
        }

        return $out;
    }

    /** @param  array<array-key, mixed>  $filter */
    private static function integer(array $filter, string $name, int $min, int $max): ?int
    {
        $value = self::scalar($filter, $name);
        if ($value === null) {
            return null;
        }

        if (! ctype_digit($value) || (int) $value < $min || (int) $value > $max) {
            throw new BadRequestHttpException("filter[{$name}] must be a whole number from {$min} to {$max}.");
        }

        return (int) $value;
    }

    /** @param  array<array-key, mixed>  $filter */
    private static function decimal(array $filter, string $name): ?string
    {
        $value = self::scalar($filter, $name);
        if ($value === null) {
            return null;
        }

        if (preg_match(self::DECIMAL, $value) !== 1) {
            throw new BadRequestHttpException("filter[{$name}] must be a decimal amount such as 1000 or 1000.00.");
        }

        return bcadd($value, '0', 2);
    }

    /** @param  array<array-key, mixed>  $filter */
    private static function boolean(array $filter, string $name): ?bool
    {
        $value = self::scalar($filter, $name);

        return match ($value) {
            null => null,
            '1', 'true' => true,
            '0', 'false' => false,
            default => throw new BadRequestHttpException("filter[{$name}] must be true or false."),
        };
    }
}
