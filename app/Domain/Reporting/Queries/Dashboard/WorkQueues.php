<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries\Dashboard;

use App\Domain\Finance\Enums\ExpenseStatus;
use App\Domain\Finance\Enums\SettlementVariance;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\VerificationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Live work queues — the status strip and the "needs attention" stack. Current state, never
 * range-bound: a held order is waiting now whatever period the owner is looking at.
 *
 * Every queue is a count plus the age of its oldest item; no customer name, phone or address
 * leaves here (rule 20). Each queue uses the same rule as the screen it links to:
 *
 *   verification     on_hold + verification pending — the risk queue (RiskQueueController)
 *   failed_bookings  Shipment::needsAttention() minus unmapped-location failures — the retry queue
 *   unmapped_areas   failed bookings blocked by an unmapped courier area (rule 32: never guessed)
 *   submitted        bulk bookings submitted, no consignment id yet (rule 24d)
 *   cod_variances    settlement lines with a variance still pending review (COD settlements)
 *   expenses         expenses awaiting approval
 *   refunds          refunds requested, not yet approved
 */
final class WorkQueues
{
    /**
     * Payment\Enums\RefundStatus::Requested. Reporting may not depend on the Payment domain
     * (deptrac), so the stored value is named here; RefundStatus cases are never renamed.
     */
    private const string REFUND_REQUESTED = 'requested';

    public const array ATTENTION = ['verification', 'failed_bookings', 'unmapped_areas', 'submitted', 'cod_variances', 'expenses', 'refunds'];

    /**
     * Orders by status right now, every status present (zero included).
     *
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $rows = DB::table('orders')->select('status')->selectRaw('COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        $counts = [];
        foreach (OrderStatus::cases() as $status) {
            $counts[$status->value] = (int) ($rows[$status->value] ?? 0);
        }

        return $counts;
    }

    /**
     * @param  list<string>  $queues  the queues to compute (the caller drops what the viewer may not see)
     * @return array<string, array{count: int, oldest_at: CarbonImmutable|null}>
     */
    public function attention(array $queues): array
    {
        $result = [];
        foreach (array_intersect(self::ATTENTION, $queues) as $queue) {
            $query = $this->queue($queue);
            $row = $query->selectRaw('COUNT(*) as aggregate, MIN('.$this->ageColumn($queue).') as oldest')->first();
            $result[$queue] = [
                'count' => (int) ($row->aggregate ?? 0),
                'oldest_at' => ($row->oldest ?? null) === null ? null : CarbonImmutable::parse((string) $row->oldest, 'UTC'),
            ];
        }

        return $result;
    }

    private function queue(string $queue): Builder
    {
        return match ($queue) {
            'verification' => DB::table('orders')
                ->where('status', OrderStatus::OnHold->value)
                ->where('verification_status', VerificationStatus::Pending->value),
            'failed_bookings' => $this->failedBookings()
                ->where(static fn (Builder $q) => $q->whereNull('failure_code')->orWhere('failure_code', '!=', Shipment::FAILURE_UNMAPPED)),
            'unmapped_areas' => $this->failedBookings()->where('failure_code', Shipment::FAILURE_UNMAPPED),
            'submitted' => DB::table('shipments')->where('status', ShipmentStatus::Submitted->value),
            'cod_variances' => DB::table('courier_settlement_items')
                ->where('variance_type', '!=', SettlementVariance::None->value)
                ->where('review_status', 'pending'),
            'expenses' => DB::table('expenses')->where('status', ExpenseStatus::Pending->value),
            'refunds' => DB::table('refunds')->where('status', self::REFUND_REQUESTED),
            default => throw new InvalidArgumentException("Unknown attention queue [{$queue}]."),
        };
    }

    /** Shipment::needsAttention(), as a base query. */
    private function failedBookings(): Builder
    {
        return DB::table('shipments')->where(static fn (Builder $q) => $q
            ->where('status', ShipmentStatus::Failed->value)
            ->orWhere(static fn (Builder $w) => $w->whereNull('dispatched_at')->where('failure_code', Shipment::FAILURE_COMPLETION)));
    }

    private function ageColumn(string $queue): string
    {
        return match ($queue) {
            'verification' => 'placed_at',
            'failed_bookings', 'unmapped_areas', 'submitted' => 'updated_at',
            default => 'created_at',
        };
    }
}
