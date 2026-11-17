<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Models\AdSpend;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Change a typed-in ad spend (campaign, source, month, amount, note). Audited with the changed
 * fields before and after; an edit that changes nothing writes no audit row.
 */
final readonly class UpdateAdSpend
{
    /** @param  string  $month  Y-m-01, a Dhaka calendar month */
    public function handle(AdSpend $spend, string $campaign, ?string $source, string $month, Money $amount, ?string $note, ?int $userId): AdSpend
    {
        return DB::transaction(function () use ($spend, $campaign, $source, $month, $amount, $note, $userId): AdSpend {
            $locked = AdSpend::query()->lockForUpdate()->findOrFail($spend->id);
            $before = AdSpendAudit::snapshot($locked);

            $locked->fill(['campaign' => $campaign, 'source' => $source, 'month' => $month, 'amount' => $amount, 'note' => $note]);
            if (! $locked->isDirty()) {
                return $locked->load(['creator', 'updater']);
            }
            $locked->updated_by = $userId;
            $locked->save();
            $after = AdSpendAudit::snapshot($locked->refresh());

            $changed = array_keys(array_diff_assoc(array_map(strval(...), $after), array_map(strval(...), $before)));
            AuditSensitive::query()->create([
                'user_id' => $userId,
                'action' => AuditAction::AdSpendUpdated,
                'model_type' => $locked->getMorphClass(),
                'model_id' => $locked->id,
                // The campaign always rides along, so the row reads on its own once edited again.
                'before' => ['campaign' => $before['campaign'], ...array_intersect_key($before, array_flip($changed))],
                'after' => ['campaign' => $after['campaign'], ...array_intersect_key($after, array_flip($changed))],
            ]);

            return $locked->load(['creator', 'updater']);
        });
    }
}
