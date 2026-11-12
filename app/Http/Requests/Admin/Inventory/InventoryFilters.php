<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Inventory;

use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Services\StockOverview;
use App\Support\DhakaDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The `filter[…]` query of the admin inventory endpoints, against an explicit allowlist
 * (CLAUDE.md rule 16): an unlisted filter, a `sort` (none is offered) or a malformed
 * value is a 400 `bad-request`, never silently ignored.
 *
 * Dates are Dhaka calendar dates (rule 11): `from` starts at 00:00 Dhaka, `to` ends at
 * 24:00 Dhaka (inclusive of that day).
 */
final readonly class InventoryFilters
{
    /** @param  array<string, string>  $values */
    private function __construct(private array $values) {}

    /**
     * @param  list<string>  $allowed
     *
     * @throws BadRequestHttpException
     */
    public static function from(Request $request, array $allowed): self
    {
        if ($request->query->has('sort')) {
            throw new BadRequestHttpException('This list has a fixed order; `sort` is not supported.');
        }

        $filter = $request->query('filter', []);
        if (! is_array($filter)) {
            throw new BadRequestHttpException('`filter` must be an object: filter[name]=value.');
        }

        $unknown = array_diff(array_map(strval(...), array_keys($filter)), $allowed);
        if ($unknown !== []) {
            throw new BadRequestHttpException('Unknown filter(s): '.implode(', ', $unknown).'. Allowed: '.implode(', ', $allowed).'.');
        }

        $values = [];
        foreach ($filter as $key => $value) {
            if (! is_string($value)) {
                throw new BadRequestHttpException("filter[{$key}] must be a single value.");
            }
            if ($value !== '') {
                $values[(string) $key] = $value;
            }
        }

        return new self($values);
    }

    public function id(string $name): ?int
    {
        $value = $this->values[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (! ctype_digit($value) || (int) $value === 0) {
            throw new BadRequestHttpException("filter[{$name}] must be a positive integer id.");
        }

        return (int) $value;
    }

    /** An active stock location; the default location when the filter is absent and `fallback` is set. */
    public function locationId(bool $fallback = true): ?int
    {
        $id = $this->id('location_id');
        if ($id === null) {
            return $fallback ? StockLocation::defaultId() : null;
        }
        if (! StockLocation::query()->whereKey($id)->where('is_active', true)->exists()) {
            throw new BadRequestHttpException('filter[location_id] is not an active stock location.');
        }

        return $id;
    }

    public function search(): ?string
    {
        $value = trim($this->values['search'] ?? '');

        return $value === '' ? null : mb_substr($value, 0, 100);
    }

    public function state(): ?string
    {
        $value = $this->values['state'] ?? null;
        if ($value !== null && ! in_array($value, StockOverview::STATES, true)) {
            throw new BadRequestHttpException('filter[state] must be one of: '.implode(', ', StockOverview::STATES).'.');
        }

        return $value;
    }

    /** @return list<StockMovementType> */
    public function types(): array
    {
        $value = $this->values['type'] ?? null;
        if ($value === null) {
            return [];
        }

        $types = [];
        foreach (explode(',', $value) as $part) {
            $type = StockMovementType::tryFrom(trim($part));
            if ($type === null) {
                throw new BadRequestHttpException('filter[type] must be a comma-separated list of: '.implode(', ', array_map(static fn (StockMovementType $t): string => $t->value, StockMovementType::cases())).'.');
            }
            $types[] = $type;
        }

        return $types;
    }

    /**
     * A comma-separated list of allowed values (`filter[reason]=damage,theft`).
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    public function listOf(string $name, array $allowed): array
    {
        $value = $this->values[$name] ?? null;
        if ($value === null) {
            return [];
        }

        $items = array_values(array_unique(array_map(trim(...), explode(',', $value))));
        foreach ($items as $item) {
            if (! in_array($item, $allowed, true)) {
                throw new BadRequestHttpException("filter[{$name}] must be a comma-separated list of: ".implode(', ', $allowed).'.');
            }
        }

        return $items;
    }

    /** Start of the `from` Dhaka day, as a UTC instant. */
    public function fromInstant(): ?CarbonImmutable
    {
        return $this->bound('from', 0);
    }

    /** End of the `to` Dhaka day (exclusive), as a UTC instant. */
    public function toInstant(): ?CarbonImmutable
    {
        $to = $this->bound('to', 1);
        $from = $this->fromInstant();
        if ($to !== null && $from !== null && $to->lessThanOrEqualTo($from)) {
            throw new BadRequestHttpException('filter[to] must not be before filter[from].');
        }

        return $to;
    }

    private function bound(string $name, int $edge): ?CarbonImmutable
    {
        $value = $this->values[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new BadRequestHttpException("filter[{$name}] must be a date, YYYY-MM-DD.");
        }

        try {
            return DhakaDate::utcBounds($value)[$edge];
        } catch (InvalidArgumentException) {
            throw new BadRequestHttpException("filter[{$name}] must be a real calendar date.");
        }
    }
}
