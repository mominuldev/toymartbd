<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Refunds approved but not yet paid out ("Owed back to a customer"), as at the end of `as_of`
 * (a Dhaka date). Money is a decimal string; `refunds` lists at most 50, oldest approval first —
 * `count` and `total` cover all of them. No customer PII.
 *
 * @property array{as_of: string, total: Money, count: int, oldest_approved_at: CarbonImmutable|null, refunds: list<array{id: int, order_id: int, order_number: string|null, amount: Money, status: string, method: string, approved_at: CarbonImmutable|null}>} $resource
 */
#[SchemaName('FinanceRefundsOwed')]
final class FinanceRefundsOwedResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $iso = static fn (?CarbonImmutable $at): ?string => $at?->utc()->format('Y-m-d\TH:i:s\Z');

        return [
            /** @var string */
            'as_of' => $r['as_of'],
            /** @var string */
            'total' => $r['total']->toDecimal(),
            /** @var int */
            'count' => $r['count'],
            /** @var string|null */
            'oldest_approved_at' => $iso($r['oldest_approved_at']),
            /** @var list<array{id: int, order_id: int, order_number: string|null, amount: string, status: 'approved'|'processing'|'failed'|'completed', method: string, approved_at: string|null}> */
            'refunds' => array_map(static fn (array $x): array => [
                'id' => $x['id'],
                'order_id' => $x['order_id'],
                'order_number' => $x['order_number'],
                'amount' => $x['amount']->toDecimal(),
                'status' => $x['status'],
                'method' => $x['method'],
                'approved_at' => $iso($x['approved_at']),
            ], $r['refunds']),
        ];
    }
}
