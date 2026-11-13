<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Reports;

use App\Domain\Finance\Reports\Enums\ReportGranularity;
use App\Domain\Finance\Reports\ReportPeriod;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Reporting\Exports\Spreadsheet;
use App\Support\DhakaDate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The query string of the read-only report endpoints, against explicit allowlists (rule 16):
 * an unlisted parameter, an unlisted filter, an unlisted sort or a malformed value is a
 * 400 `bad-request`, never silently ignored.
 *
 * Dates are Dhaka calendar dates (rule 11), inclusive at both ends. Without
 * `filter[from]`/`filter[to]` a report covers the last seven Dhaka days, today included.
 */
final readonly class ReportQuery
{
    /** The most buckets one series returns (a year and a bit of days). */
    public const int MAX_BUCKETS = 400;

    /**
     * @param  array<string, string>  $filters
     * @param  array<string, string>  $params
     */
    private function __construct(private array $filters, private array $params) {}

    /**
     * @param  list<string>  $filters  allowed filter[…] names
     * @param  list<string>  $params  allowed top-level parameters besides `filter`
     *
     * @throws BadRequestHttpException
     */
    public static function from(Request $request, array $filters, array $params = []): self
    {
        $unknown = array_diff(array_map(strval(...), array_keys($request->query->all())), [...$params, 'filter']);
        if ($unknown !== []) {
            throw new BadRequestHttpException('Unknown parameter(s): '.implode(', ', $unknown).'. Allowed: '.implode(', ', [...$params, 'filter']).'.');
        }

        $filter = $request->query('filter', []);
        if (! is_array($filter)) {
            throw new BadRequestHttpException('`filter` must be an object: filter[name]=value.');
        }
        $unknownFilters = array_diff(array_map(strval(...), array_keys($filter)), $filters);
        if ($unknownFilters !== []) {
            throw new BadRequestHttpException('Unknown filter(s): '.implode(', ', $unknownFilters).'. Allowed: '.implode(', ', $filters).'.');
        }

        $values = [];
        foreach ($filter as $key => $value) {
            if (! is_string($value)) {
                throw new BadRequestHttpException("filter[{$key}] must be a single value.");
            }
            if (trim($value) !== '') {
                $values[(string) $key] = trim($value);
            }
        }

        $top = [];
        foreach ($params as $name) {
            $value = $request->query($name);
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_string($value)) {
                throw new BadRequestHttpException("`{$name}` must be a single value.");
            }
            $top[$name] = $value;
        }

        return new self($values, $top);
    }

    public function period(): ReportPeriod
    {
        $from = $this->filters['from'] ?? null;
        $to = $this->filters['to'] ?? null;

        if ($from === null && $to === null) {
            return ReportPeriod::lastSevenDays();
        }

        try {
            return ReportPeriod::of($from ?? $to, $to ?? DhakaDate::today());
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException('filter[from]/filter[to]: '.$e->getMessage());
        }
    }

    /** A Dhaka date that is today or earlier, or null. */
    public function date(string $name): ?string
    {
        $value = $this->filters[$name] ?? null;
        if ($value === null) {
            return null;
        }
        try {
            $period = ReportPeriod::of($value, $value);
        } catch (InvalidArgumentException) {
            throw new BadRequestHttpException("filter[{$name}] must be a real date, YYYY-MM-DD.");
        }
        if ($period->fromDate() > DhakaDate::today()) {
            throw new BadRequestHttpException("filter[{$name}] must not be in the future.");
        }

        return $period->fromDate();
    }

    public function id(string $name): ?int
    {
        $value = $this->filters[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (! ctype_digit($value) || (int) $value === 0) {
            throw new BadRequestHttpException("filter[{$name}] must be a positive integer id.");
        }

        return (int) $value;
    }

    /** An active stock location id, or null for every location. */
    public function locationId(): ?int
    {
        $id = $this->id('location_id');
        if ($id !== null && ! StockLocation::query()->whereKey($id)->exists()) {
            throw new BadRequestHttpException('filter[location_id] is not a stock location.');
        }

        return $id;
    }

    public function search(): ?string
    {
        $value = $this->filters['search'] ?? null;

        return $value === null ? null : mb_substr($value, 0, 100);
    }

    /**
     * A slug-like filter value, optionally from an allowlist.
     *
     * @param  list<string>|null  $allowed
     */
    public function filterIn(string $name, ?array $allowed = null, int $max = 32): ?string
    {
        $value = $this->filters[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if ($allowed !== null && ! in_array($value, $allowed, true)) {
            throw new BadRequestHttpException("filter[{$name}] must be one of: ".implode(', ', $allowed).'.');
        }
        if (mb_strlen($value) > $max || preg_match('/^[a-z0-9_\-]+$/', $value) !== 1) {
            throw new BadRequestHttpException("filter[{$name}] is not a valid value.");
        }

        return $value;
    }

    /**
     * A top-level enum-like parameter.
     *
     * @param  list<string>  $allowed
     */
    public function choice(string $name, array $allowed, string $default): string
    {
        $value = $this->params[$name] ?? $default;
        if (! in_array($value, $allowed, true)) {
            throw new BadRequestHttpException("`{$name}` must be one of: ".implode(', ', $allowed).'.');
        }

        return $value;
    }

    /**
     * A report export's `format` (csv by default, so callers from before XLSX get the same
     * CSV) and the `locale` of its labels and sheet name (the request locale by default).
     * Both must be allowed parameters of this query; an unknown value of either is a 400.
     *
     * @return array{0: 'csv'|'xlsx', 1: 'en'|'bn'}
     */
    public function exportOptions(): array
    {
        /** @var 'csv'|'xlsx' $format */
        $format = $this->choice('format', Spreadsheet::formats(), Spreadsheet::CSV);
        /** @var 'en'|'bn' $locale */
        $locale = $this->choice('locale', ['en', 'bn'], App::getLocale() === 'bn' ? 'bn' : 'en');

        return [$format, $locale];
    }

    /**
     * `sort=field` or `sort=-field` over an allowlist.
     *
     * @param  list<string>  $allowed
     */
    public function sort(array $allowed, string $default): string
    {
        $value = $this->params['sort'] ?? $default;
        if (! in_array(ltrim($value, '-'), $allowed, true) || substr_count($value, '-') > 1 || str_contains(ltrim($value, '-'), '-')) {
            throw new BadRequestHttpException('`sort` must be one of: '.implode(', ', $allowed).' (prefix - for descending).');
        }

        return $value;
    }

    public function granularity(ReportPeriod $period): ReportGranularity
    {
        $default = match (true) {
            $period->days() <= 62 => ReportGranularity::Day,
            $period->days() <= 366 => ReportGranularity::Month,
            default => ReportGranularity::Year,
        };

        $granularity = ReportGranularity::from($this->choice('group', ReportGranularity::values(), $default->value));
        if (count($period->buckets($granularity)) > self::MAX_BUCKETS) {
            throw new BadRequestHttpException('Too many buckets: at most '.self::MAX_BUCKETS.' per series; group by a longer period.');
        }

        return $granularity;
    }
}
