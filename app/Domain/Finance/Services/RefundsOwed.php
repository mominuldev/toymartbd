<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Owed back to a customer" (admin spec A19 cash card): refunds approved but not yet paid out,
 * as at the end of a Dhaka date. A refund counts from its `approved_at` until its `processed_at`
 * (payout recorded, status `completed`); `approved`, `processing` and `failed` are still owed,
 * `requested` is not yet owed and `rejected` never is. Cash, not profit: nothing here is in the
 * ledger until the payout is entered. Read-only.
 *
 * @phpstan-type OwedRefund array{id: int, order_id: int, order_number: string|null, amount: Money, status: string, method: string, approved_at: CarbonImmutable|null}
 */
final readonly class RefundsOwed
{
    /** Rows listed beside the total, oldest approval first. */
    public const int LIST_LIMIT = 50;

    private const array OPEN = ['approved', 'processing', 'failed'];

    /** @return array{total: Money, count: int, oldest_approved_at: CarbonImmutable|null, refunds: list<OwedRefund>} */
    public function asOf(?string $asOf = null): array
    {
        $asOf ??= DhakaDate::today();
        [, $end] = DhakaDate::utcBounds($asOf);

        $query = DB::table('refunds as r')
            ->leftJoin('orders as o', 'o.id', '=', 'r.order_id')
            ->whereNotNull('r.approved_at')
            ->where('r.approved_at', '<', $end)
            ->where(static function ($q) use ($end): void {
                $q->whereIn('r.status', self::OPEN)
                    // Paid out after the date: still owed at its end.
                    ->orWhere(static fn ($c) => $c->where('r.status', 'completed')->where('r.processed_at', '>=', $end));
            });

        $total = Money::zero();
        $count = 0;
        $oldest = null;
        $refunds = [];
        foreach ((clone $query)->orderBy('r.approved_at')->orderBy('r.id')->get(['r.id', 'r.order_id', 'o.number', 'r.amount', 'r.status', 'r.method', 'r.approved_at']) as $row) {
            $amount = is_float($row->amount) ? Money::of(number_format($row->amount, 2, '.', '')) : Money::of((string) $row->amount);
            $total = $total->plus($amount);
            $count++;
            $approved = $row->approved_at === null ? null : CarbonImmutable::parse((string) $row->approved_at, 'UTC');
            $oldest ??= $approved;
            if (count($refunds) < self::LIST_LIMIT) {
                $refunds[] = [
                    'id' => (int) $row->id,
                    'order_id' => (int) $row->order_id,
                    'order_number' => is_string($row->number) ? $row->number : null,
                    'amount' => $amount,
                    'status' => (string) $row->status,
                    'method' => (string) $row->method,
                    'approved_at' => $approved,
                ];
            }
        }

        return ['total' => $total, 'count' => $count, 'oldest_approved_at' => $oldest, 'refunds' => $refunds];
    }
}
