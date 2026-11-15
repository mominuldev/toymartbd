<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * Barcode label stock (owner request 2026-11-16). All sizes in millimetres.
 *
 * - `roll_38x25`, `roll_50x25` — one label per page, for the thermal roll printers common in
 *   Bangladeshi shops (the printer driver's page size is the label).
 * - `a4_65` — A4 sheet, 5 × 13 labels of 38.1 × 21.2 (Avery L7651: 10.7 top, 4.7 side margin,
 *   2.5 column gap, no row gap).
 * - `a4_24` — A4 sheet, 3 × 8 labels of 70 × 37, no gaps (the Avery 3474 layout). Eight rows of
 *   37 take 296 of the sheet's 297, so the top margin is 0.5, not the 4.5 of the 70 × 36
 *   sheets — 4.5 would push the last row 3.5 off the page.
 */
enum LabelTemplate: string
{
    case Roll38x25 = 'roll_38x25';
    case Roll50x25 = 'roll_50x25';
    case A4x65 = 'a4_65';
    case A4x24 = 'a4_24';

    /** @return array{0: float, 1: float} width, height of one label */
    public function labelSize(): array
    {
        return match ($this) {
            self::Roll38x25 => [38.0, 25.0],
            self::Roll50x25 => [50.0, 25.0],
            self::A4x65 => [38.1, 21.2],
            self::A4x24 => [70.0, 37.0],
        };
    }

    /** @return array{0: float, 1: float} width, height of the page */
    public function pageSize(): array
    {
        return $this->isSheet() ? [210.0, 297.0] : $this->labelSize();
    }

    public function isSheet(): bool
    {
        return $this === self::A4x65 || $this === self::A4x24;
    }

    public function columns(): int
    {
        return match ($this) {
            self::A4x65 => 5,
            self::A4x24 => 3,
            default => 1,
        };
    }

    public function rows(): int
    {
        return match ($this) {
            self::A4x65 => 13,
            self::A4x24 => 8,
            default => 1,
        };
    }

    public function perPage(): int
    {
        return $this->columns() * $this->rows();
    }

    public function marginTop(): float
    {
        return match ($this) {
            self::A4x65 => 10.7,
            self::A4x24 => 0.5,
            default => 0.0,
        };
    }

    /** Left margin; a sheet is centred, so A4 minus the columns and gaps, halved. */
    public function marginLeft(): float
    {
        if (! $this->isSheet()) {
            return 0.0;
        }

        return round((210.0 - $this->columns() * $this->labelSize()[0] - ($this->columns() - 1) * $this->columnGap()) / 2, 2);
    }

    public function columnGap(): float
    {
        return $this === self::A4x65 ? 2.5 : 0.0;
    }

    /**
     * Top-left corner of the label in slot `$slot` (0-based, row-major) on its page.
     *
     * @return array{0: float, 1: float}
     */
    public function origin(int $slot): array
    {
        [$width, $height] = $this->labelSize();
        $column = $slot % $this->columns();
        $row = intdiv($slot, $this->columns());

        return [round($this->marginLeft() + $column * ($width + $this->columnGap()), 2), round($this->marginTop() + $row * $height, 2)];
    }
}
