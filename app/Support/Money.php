<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An amount of Bangladeshi taka, exact to the poisha (0.01).
 *
 * Stored as DECIMAL(14,2) through App\Support\Casts\MoneyCast (CLAUDE.md rule 6).
 * Arithmetic is bcmath on decimal strings, never float, so 0.1 + 0.2 is 0.30.
 * Rounding is half away from zero at the third decimal: 0.005 → 0.01.
 *
 * Formatting for display (৳ sign, digit grouping) is a presentation concern and
 * lives in the locale formatters, not here.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private const SCALE = 2;

    /** DECIMAL(14,2): twelve integer digits. */
    private const MAX_ABS = '999999999999.99';

    /** Canonical form: exactly two decimals, no leading zero, at most twelve integer digits. */
    private const CANONICAL = '/^-?(?:0|[1-9]\d{0,11})\.\d\d$/';

    private function __construct(public string $amount) {}

    /**
     * @param  string|int  $amount  a decimal string ("2450", "2450.5", "-10.00") or whole taka.
     *                              Floats are rejected on purpose: by the time a float exists,
     *                              precision may already be gone.
     */
    public static function of(string|int $amount): self
    {
        $value = is_int($amount) ? (string) $amount : trim($amount);

        if (preg_match(self::CANONICAL, $value) === 1 && $value !== '-0.00') {
            return new self($value); // already what normalise() would return (fast path, see there)
        }
        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException("Not a decimal amount: [{$value}]");
        }

        return self::normalise($value);
    }

    public static function fromPoisha(int $poisha): self
    {
        return self::normalise(bcdiv((string) $poisha, '100', self::SCALE));
    }

    public static function zero(): self
    {
        return new self('0.00');
    }

    public function plus(self $other): self
    {
        return self::exact(bcadd($this->amount, $other->amount, self::SCALE));
    }

    public function minus(self $other): self
    {
        return self::exact(bcsub($this->amount, $other->amount, self::SCALE));
    }

    /** Multiply by a quantity or a decimal factor ("1.5"), rounding half away from zero. */
    public function times(string|int $factor): self
    {
        return self::normalise(bcmul($this->amount, self::decimal($factor), self::SCALE + 4));
    }

    /** A percentage of this amount: Money::of('99.99')->percentage('12.5') is 12.50. */
    public function percentage(string|int $percent): self
    {
        $product = bcmul($this->amount, self::decimal($percent), self::SCALE + 4);

        return self::normalise(bcdiv($product, '100', self::SCALE + 4));
    }

    /**
     * Split by ratios without losing or inventing a poisha. The remainder goes one
     * poisha at a time to the shares with the largest fractional parts, earliest first.
     * Money::of('100')->allocate(1, 1, 1) is [33.34, 33.33, 33.33].
     *
     * @return list<self>
     */
    public function allocate(int ...$ratios): array
    {
        if ($ratios === [] || min($ratios) < 0 || array_sum($ratios) === 0) {
            throw new InvalidArgumentException('Ratios must be non-negative and not all zero.');
        }

        $total = $this->toPoisha();
        $sum = array_sum($ratios);
        $shares = [];
        $remainders = [];

        foreach ($ratios as $i => $ratio) {
            $exact = intdiv($total * $ratio, $sum);
            $shares[$i] = $exact;
            $remainders[$i] = abs(($total * $ratio) % $sum);
        }

        $left = $total - array_sum($shares);
        $step = $left <=> 0;
        arsort($remainders, SORT_NUMERIC);

        foreach (array_keys($remainders) as $i) {
            if ($left === 0) {
                break;
            }
            $shares[$i] += $step;
            $left -= $step;
        }

        return array_map(self::fromPoisha(...), array_values($shares));
    }

    public function negate(): self
    {
        return self::normalise(bcmul($this->amount, '-1', self::SCALE));
    }

    public function compareTo(self $other): int
    {
        return bccomp($this->amount, $other->amount, self::SCALE);
    }

    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) === 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) < 0;
    }

    public function toPoisha(): int
    {
        return (int) bcmul($this->amount, '100', 0);
    }

    /** "2450.00" — the exact value written to a DECIMAL(14,2) column. */
    public function toDecimal(): string
    {
        return $this->amount;
    }

    public function __toString(): string
    {
        return $this->amount;
    }

    /** Serialised as a string so no JSON client ever parses it into a float. */
    public function jsonSerialize(): string
    {
        return $this->amount;
    }

    private static function decimal(string|int $value): string
    {
        $value = is_int($value) ? (string) $value : trim($value);

        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException("Not a decimal factor: [{$value}]");
        }

        return $value;
    }

    /**
     * The sum or difference of two Money values at scale 2: bcmath already returns it in canonical
     * form (two decimals, no leading zero), so only the range and negative zero need checking.
     */
    private static function exact(string $value): self
    {
        if ($value !== '-0.00' && strlen($value) - ($value[0] === '-' ? 1 : 0) <= 15) {
            return new self($value);
        }

        return self::normalise($value);
    }

    /** Round half away from zero to two places, and enforce the column's range. */
    private static function normalise(string $value): self
    {
        // Fast path (Phase 9, docs/audits/PERFORMANCE.md P-04): a value already in canonical form —
        // exactly two decimals, no leading zero, at most twelve integer digits, not negative zero —
        // is what the rounding below would return unchanged. plus()/minus() of two Money values
        // always lands here, and report loops do hundreds of thousands of them.
        if (preg_match(self::CANONICAL, $value) === 1 && $value !== '-0.00') {
            return new self($value);
        }

        $negative = str_starts_with($value, '-');
        $abs = ltrim($value, '-');
        $rounded = bcadd($abs, '0.005', self::SCALE + 1);
        $rounded = bcadd($rounded, '0', self::SCALE);

        if (bccomp($rounded, self::MAX_ABS, self::SCALE) > 0) {
            throw new InvalidArgumentException("Amount exceeds DECIMAL(14,2): [{$value}]");
        }

        $isZero = bccomp($rounded, '0', self::SCALE) === 0;

        return new self(($negative && ! $isZero ? '-' : '').$rounded);
    }
}
