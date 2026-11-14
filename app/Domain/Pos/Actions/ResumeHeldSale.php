<?php

declare(strict_types=1);

namespace App\Domain\Pos\Actions;

use App\Domain\Pos\Exceptions\PosRefused;
use App\Domain\Pos\Models\PosHeldSale;
use App\Domain\Pos\Models\PosSession;
use Illuminate\Support\Facades\DB;

/**
 * Take a parked cart back onto the register (or discard it — the same move: it leaves the
 * held list). Once only: the row is locked and a second resume is a 409, so two tills cannot
 * both ring up the same parked basket.
 */
final readonly class ResumeHeldSale
{
    /** @throws PosRefused when already resumed or the drawer is closed */
    public function handle(PosHeldSale $hold, int $userId): PosHeldSale
    {
        return DB::transaction(function () use ($hold, $userId): PosHeldSale {
            /** @var PosHeldSale $locked */
            $locked = PosHeldSale::query()->lockForUpdate()->findOrFail($hold->id);
            if ($locked->resumed_at !== null) {
                throw PosRefused::holdResumed();
            }
            $session = PosSession::query()->findOrFail($locked->session_id);
            if (! $session->isOpen()) {
                throw PosRefused::sessionClosed();
            }

            $locked->forceFill(['resumed_at' => now(), 'resumed_by' => $userId])->save();

            return $locked;
        });
    }
}
