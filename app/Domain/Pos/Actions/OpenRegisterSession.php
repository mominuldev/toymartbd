<?php

declare(strict_types=1);

namespace App\Domain\Pos\Actions;

use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Pos\Enums\PosSessionStatus;
use App\Domain\Pos\Exceptions\PosRefused;
use App\Domain\Pos\Models\PosSession;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Open a cash drawer on a register at a branch with an opening float (Figma B · 33 header:
 * "drawer opened 10:02 am with ৳5,000"). One open session per register and one per cashier:
 * both are UNIQUE keys on the row, so two clicks — or two tills racing — cannot both win;
 * the loser gets a 409.
 *
 * Refused at an inactive location or a partner pickup point (nothing is sold there).
 */
final readonly class OpenRegisterSession
{
    /** @throws PosRefused */
    public function handle(int $locationId, string $register, Money $openingFloat, int $cashierId): PosSession
    {
        $register = trim($register);

        try {
            return DB::transaction(function () use ($locationId, $register, $openingFloat, $cashierId): PosSession {
                $location = StockLocation::query()->findOrFail($locationId);
                if (! $location->is_active || $location->type === StockLocationType::Pickup) {
                    throw PosRefused::locationNotSelling();
                }
                if (PosSession::query()->where('open_key', PosSession::openKey($locationId, $register))->exists()) {
                    throw PosRefused::registerBusy();
                }
                if (PosSession::query()->where('cashier_open_key', (string) $cashierId)->exists()) {
                    throw PosRefused::cashierBusy();
                }

                return PosSession::query()->create([
                    'location_id' => $locationId,
                    'register' => $register,
                    'status' => PosSessionStatus::Open,
                    'open_key' => PosSession::openKey($locationId, $register),
                    'cashier_open_key' => (string) $cashierId,
                    'opened_by' => $cashierId,
                    'opened_at' => now(),
                    'opening_float' => $openingFloat,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Lost the race to a concurrent open on the same register or by the same cashier.
            throw PosRefused::registerBusy();
        }
    }
}
