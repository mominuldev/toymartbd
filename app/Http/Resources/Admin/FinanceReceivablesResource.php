<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use App\Support\Money;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * COD receivables = recognised − received, as at the end of `as_of` (a Dhaka date). Money
 * is a decimal string. `aging` buckets the outstanding amount by days since delivery.
 *
 * @property array{as_of: string, recognised: Money, received: Money, outstanding: Money, open_orders: int, aging: array<string, Money>} $resource
 */
#[SchemaName('FinanceReceivables')]
final class FinanceReceivablesResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var string */
            'as_of' => $r['as_of'],
            /** @var string */
            'recognised' => $r['recognised']->toDecimal(),
            /** @var string */
            'received' => $r['received']->toDecimal(),
            /** @var string */
            'outstanding' => $r['outstanding']->toDecimal(),
            /** @var int */
            'open_orders' => $r['open_orders'],
            /** @var array{'0_7': string, '8_15': string, '16_30': string, '31_plus': string} */
            'aging' => array_map(static fn (Money $m): string => $m->toDecimal(), $r['aging']),
        ];
    }
}
