<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Models\AdSpend;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Record what was spent on one ad campaign in one Dhaka month (P10, Marketing ROI). Typed in
 * by staff — we never read an ad account. Audited: ad spend drives the return figures the
 * owner reads, so who typed which number is kept.
 */
final readonly class CreateAdSpend
{
    /** @param  string  $month  Y-m-01, a Dhaka calendar month */
    public function handle(string $campaign, ?string $source, string $month, Money $amount, ?string $note, ?int $userId): AdSpend
    {
        return DB::transaction(function () use ($campaign, $source, $month, $amount, $note, $userId): AdSpend {
            $spend = AdSpend::query()->create([
                'campaign' => $campaign,
                'source' => $source,
                'month' => $month,
                'amount' => $amount,
                'note' => $note,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            AuditSensitive::query()->create([
                'user_id' => $userId,
                'action' => AuditAction::AdSpendCreated,
                'model_type' => $spend->getMorphClass(),
                'model_id' => $spend->id,
                'before' => null,
                'after' => AdSpendAudit::snapshot($spend),
            ]);

            return $spend->refresh()->load(['creator', 'updater']);
        });
    }
}
