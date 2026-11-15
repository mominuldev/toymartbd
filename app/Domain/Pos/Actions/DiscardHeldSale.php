<?php

declare(strict_types=1);

namespace App\Domain\Pos\Actions;

use App\Domain\Pos\Exceptions\PosRefused;
use App\Domain\Pos\Models\PosHeldSale;
use App\Domain\Pos\Models\PosSession;
use Illuminate\Support\Facades\DB;

/**
 * Throw a parked cart away (POS F8 "Held sales"). Once only, under the hold's row lock: a hold
 * already resumed or discarded is a 409, and only a hold on an OPEN drawer can be discarded (a
 * closed drawer's holds are its record). `discarded_at` / `discarded_by` are the audit trail —
 * holds hold no money or stock, so nothing else changes.
 */
final readonly class DiscardHeldSale
{
    /** @throws PosRefused already resumed / discarded, or the drawer is closed (409) */
    public function handle(PosHeldSale $hold, int $userId): PosHeldSale
    {
        return DB::transaction(function () use ($hold, $userId): PosHeldSale {
            /** @var PosHeldSale $locked */
            $locked = PosHeldSale::query()->lockForUpdate()->findOrFail($hold->id);
            if ($locked->discarded_at !== null) {
                throw PosRefused::holdDiscarded();
            }
            if ($locked->resumed_at !== null) {
                throw PosRefused::holdResumed();
            }
            $session = PosSession::query()->findOrFail($locked->session_id);
            if (! $session->isOpen()) {
                throw PosRefused::sessionClosed();
            }

            $locked->forceFill(['discarded_at' => now(), 'discarded_by' => $userId])->save();

            return $locked;
        });
    }
}
