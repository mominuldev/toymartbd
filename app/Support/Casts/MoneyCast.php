<?php

declare(strict_types=1);

namespace App\Support\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Eloquent cast for DECIMAL(14,2) money columns: `'total' => MoneyCast::class`.
 *
 * Reads always yield Money (or null). Writes accept Money or a decimal string;
 * a float is rejected rather than silently rounded.
 *
 * @implements CastsAttributes<Money|null, mixed>
 */
final class MoneyCast implements CastsAttributes
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
}
