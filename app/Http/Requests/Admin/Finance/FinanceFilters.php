<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Finance;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The `filter[…]` and `sort` query of the admin finance lists, against explicit allowlists
 * (rule 16): an unlisted filter or sort, or a malformed value, is a 400 `bad-request`.
 * Dates are Dhaka calendar dates (`YYYY-MM-DD`, rule 11) and compare with the ledger's
 * `occurred_on` / an expense's `expense_date` directly — both are already Dhaka dates.
 */
final readonly class FinanceFilters
{
    /** @param array<string, string> $values */
    private function __construct(private array $values, public ?string $sort, public bool $descending) {}

    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $sorts
     */
    public static function from(Request $request, array $allowed, array $sorts, string $defaultSort): self
    {
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
            if (trim($value) !== '') {
                $values[(string) $key] = trim($value);
            }
        }

        $sort = $request->query('sort', $defaultSort);
        if (! is_string($sort) || ! in_array(ltrim($sort, '-'), $sorts, true)) {
            throw new BadRequestHttpException('sort must be one of: '.implode(', ', $sorts).' (prefix `-` for descending).');
        }

        return new self($values, ltrim($sort, '-'), str_starts_with($sort, '-'));
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

    public function bool(string $name): ?bool
    {
        $value = $this->values[$name] ?? null;

        return match ($value) {
            null => null,
            'true', '1' => true,
            'false', '0' => false,
            default => throw new BadRequestHttpException("filter[{$name}] must be true or false."),
        };
    }

    /** A short identifier-like value (`source_type`): lower-case letters and underscores. */
    public function key(string $name): ?string
    {
        $value = $this->values[$name] ?? null;
        if ($value !== null && preg_match('/^[a-z_]{1,48}$/', $value) !== 1) {
            throw new BadRequestHttpException("filter[{$name}] must be lower-case letters and underscores.");
        }

        return $value;
    }

    public function search(): ?string
    {
        $value = $this->values['search'] ?? null;

        return $value === null ? null : mb_substr($value, 0, 100);
    }

    /**
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

    /** A Dhaka date filter as Y-m-d; `to` before `from` is a 400. */
    public function date(string $name): ?string
    {
        $value = $this->values[$name] ?? null;
        if ($value === null) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || $parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new BadRequestHttpException("filter[{$name}] must be a real date, YYYY-MM-DD.");
        }
        if ($name === 'to' && ($from = $this->values['from'] ?? null) !== null && $value < $from) {
            throw new BadRequestHttpException('filter[to] must not be before filter[from].');
        }

        return $value;
    }
}
