<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront;

use App\Domain\Search\DTOs\SearchRequest;
use App\Domain\Search\Enums\SearchSort;
use App\Domain\Search\Support\SearchText;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Parses `GET /storefront/search` and `GET /storefront/search/suggest` against an
 * explicit allowlist (CLAUDE.md rule 16), with the same 400 `bad-request` contract as
 * the listing.
 *
 * `filter[...]` is delegated to ProductListingQuery, so search filters are the listing's
 * filters — same names, same validation, same meaning — and any filter added there is
 * accepted here automatically.
 */
final class SearchQuery
{
    /** @var list<string> */
    public const array PARAMETERS = ['q', 'filter', 'sort', 'page'];

    /** @var list<string> */
    public const array SUGGEST_PARAMETERS = ['q'];

    /** Characters, after trimming and normalisation. */
    public const int MAX_QUERY = 100;

    public const int MIN_SUGGEST_QUERY = 2;

    public const int PER_PAGE = 24;

    public static function fromRequest(Request $request): SearchRequest
    {
        $query = $request->query->all();
        self::allow($query, self::PARAMETERS);

        $page = $query['page'] ?? null;
        $maxPage = intdiv((int) config('search.max_total_hits', 1000), self::PER_PAGE);
        if ($page !== null && (! is_string($page) || ! ctype_digit($page) || (int) $page < 1 || (int) $page > $maxPage)) {
            throw new BadRequestHttpException("page must be a whole number from 1 to {$maxPage}.");
        }

        $listing = ProductListingQuery::fromRequest(new Request(array_key_exists('filter', $query) ? ['filter' => $query['filter']] : []));

        return new SearchRequest(
            self::text($query['q'] ?? null, 1),
            $listing->filters,
            self::sort($query['sort'] ?? null),
            $page === null ? 1 : (int) $page,
            self::PER_PAGE,
        );
    }

    /** The autocomplete query (2+ characters). */
    public static function suggestFromRequest(Request $request): string
    {
        $query = $request->query->all();
        self::allow($query, self::SUGGEST_PARAMETERS);

        return self::text($query['q'] ?? null, self::MIN_SUGGEST_QUERY);
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @param  list<string>  $allowed
     */
    private static function allow(array $query, array $allowed): void
    {
        $unknown = array_diff(array_map(strval(...), array_keys($query)), $allowed);
        if ($unknown !== []) {
            throw new BadRequestHttpException('Unknown query parameter(s): '.implode(', ', $unknown).'. Allowed: '.implode(', ', $allowed).'.');
        }
    }

    private static function text(mixed $value, int $min): string
    {
        if (! is_string($value)) {
            throw new BadRequestHttpException('q is required: the search text.');
        }

        $text = SearchText::normalize($value);
        $length = mb_strlen($text);
        if ($length < $min || $length > self::MAX_QUERY) {
            throw new BadRequestHttpException("q must be {$min} to ".self::MAX_QUERY.' characters.');
        }
        if (preg_match('/[\p{Cc}]/u', $text) === 1) {
            throw new BadRequestHttpException('q must not contain control characters.');
        }

        return $text;
    }

    private static function sort(mixed $value): SearchSort
    {
        if ($value === null) {
            return SearchSort::Relevance;
        }

        $sort = is_string($value) ? SearchSort::tryFrom($value) : null;
        if ($sort === null) {
            $allowed = implode(', ', array_map(static fn (SearchSort $s): string => $s->value, SearchSort::cases()));
            throw new BadRequestHttpException("sort must be one of: {$allowed}.");
        }

        return $sort;
    }
}
