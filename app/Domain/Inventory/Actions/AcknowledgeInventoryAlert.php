<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\InventoryAlertStatus;
use App\Domain\Inventory\Models\InventoryAlert;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A person has seen an inventory alert and closes it, with a note on what they found
 * (P5-INV). The only way a drift alert closes: reconciliation never closes one itself.
 * Acknowledging changes no stock — a real correction is a separate, explicit stock
 * adjustment, so it is itself on the ledger. If the drift is still there next night, a
 * new alert opens.
 */
final readonly class AcknowledgeInventoryAlert
{
    /** @throws ConflictHttpException when the alert is no longer open */
    public function handle(int $alertId, int $userId, ?string $note = null): InventoryAlert
    {
        return DB::transaction(function () use ($alertId, $userId, $note): InventoryAlert {
            /** @var InventoryAlert $alert */
            $alert = InventoryAlert::query()->lockForUpdate()->findOrFail($alertId);
            if ($alert->status !== InventoryAlertStatus::Open) {
                throw new ConflictHttpException("Alert {$alert->id} is {$alert->status->value}, not open.");
            }

            $alert->status = InventoryAlertStatus::Acknowledged;
            $alert->acknowledged_by = $userId;
            $alert->acknowledged_at = now();
            $alert->note = $note;
            $alert->save();

            return $alert;
        });
    }
}
