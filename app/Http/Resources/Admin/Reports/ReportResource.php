<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Reports;

use App\Http\Resources\ApiResource;
use App\Support\Money;

/**
 * Base for the read-only report resources: report services return Money objects; the
 * wire format is a decimal string (rule 6), never a float.
 */
abstract class ReportResource extends ApiResource
{
    /** Money → decimal string, recursively. */
    protected static function plain(mixed $value): mixed
    {
        if ($value instanceof Money) {
            return $value->toDecimal();
        }
        if (is_array($value)) {
            return array_map(self::plain(...), $value);
        }

        return $value;
    }
}
