<?php

declare(strict_types=1);

namespace App\Support\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Eloquent cast for DECIMAL(14,2) money columns: `'total' => MoneyCast::class`.
 *
 * Reads always yield Money (or null). Writes accept Money or a decimal string;
 * a float is rejected rather than silently rounded.
 *
 * Dirty checks compare amounts, not strings: SQLite hands a DECIMAL back as 1250
 * while the cast writes "1250.00", and those must not count as a change (a false
 * "price changed" would write a spurious audit row).
 *
 * @implements CastsAttributes<Money|null, mixed>
 */
final class MoneyCast implements CastsAttributes, ComparesCastableAttributes
{
    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            // Some drivers hand DECIMAL back as float; format exactly what the column holds.
            $value = number_format($value, 2, '.', '');
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("Column [{$key}] holds a non-numeric value.");
        }

        return Money::of($value);
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof Money => $value->toDecimal(),
            is_string($value) => Money::of($value)->toDecimal(),
            default => throw new InvalidArgumentException(
                "Column [{$key}] accepts Money or a decimal string, got ".get_debug_type($value).'.',
            ),
        };
    }

    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        $first = $this->get($model, $key, $firstValue, []);
        $second = $this->get($model, $key, $secondValue, []);

        return $first === null || $second === null ? $first === $second : $first->equals($second);
    }
}
