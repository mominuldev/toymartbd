<?php

declare(strict_types=1);

namespace App\Domain\Pos\Actions;

use App\Domain\Pos\Exceptions\PosRefused;
use App\Domain\Pos\Models\PosHeldSale;
use App\Domain\Pos\Models\PosSession;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * "Hold sale": park the cart on this drawer so the next customer can be served. Stores
 * variant ids and quantities only — no prices (resume re-prices), no stock reservation (a
 * parked cart must not starve the shelf).
 */
final readonly class HoldSale
{
    /**
     * @param  array<int, int>  $lines  variant id → quantity
     *
     * @throws PosRefused when the drawer is closed
     */
    public function handle(PosSession $session, array $lines, ?string $couponCode, ?PhoneNumber $phone, ?string $label, int $userId): PosHeldSale
    {
        ksort($lines);

        return DB::transaction(function () use ($session, $lines, $couponCode, $phone, $label, $userId): PosHeldSale {
            /** @var PosSession $locked */
            $locked = PosSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! $locked->isOpen()) {
                throw PosRefused::sessionClosed();
            }

            $stored = [];
            foreach ($lines as $variantId => $quantity) {
                $stored[(string) $variantId] = $quantity;
            }

            return PosHeldSale::query()->create([
                'session_id' => $locked->id,
                'label' => $label !== null && trim($label) !== '' ? trim($label) : null,
                'lines' => $stored,
                'coupon_code' => $couponCode !== null && trim($couponCode) !== '' ? trim($couponCode) : null,
                'customer_phone' => $phone?->e164(),
                'held_by' => $userId,
            ]);
        });
    }
}
