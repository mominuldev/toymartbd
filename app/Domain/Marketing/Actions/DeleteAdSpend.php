<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Models\AdSpend;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use Illuminate\Support\Facades\DB;

/**
 * Remove a typed-in ad spend (a wrong entry). Not a ledger row — ad spend is the owner's own
 * note of what an ad cost, used only for Marketing ROI — so it may be deleted; the audit row
 * keeps what it was.
 */
final readonly class DeleteAdSpend
{
    public function handle(AdSpend $spend, ?int $userId): void
    {
        DB::transaction(function () use ($spend, $userId): void {
            $locked = AdSpend::query()->lockForUpdate()->findOrFail($spend->id);

            AuditSensitive::query()->create([
                'user_id' => $userId,
                'action' => AuditAction::AdSpendDeleted,
                'model_type' => $locked->getMorphClass(),
                'model_id' => $locked->id,
                'before' => AdSpendAudit::snapshot($locked),
                'after' => null,
            ]);

            $locked->delete();
        });
    }
}
