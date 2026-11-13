<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\Models\CourierProvider;
use Illuminate\Support\Facades\DB;

/**
 * Turn a courier on or off, or make it the default (`PATCH /settings/couriers/{provider}`).
 * The default provider's rate card prices every storefront quote (DATABASE-DESIGN §5.1
 * step 3), so exactly one active provider is the default: making one default clears the
 * flag on the others. The FormRequest refuses switching off the default or the last active
 * provider — either would leave the storefront with no quote at all.
 */
final readonly class UpdateCourierProviderStatus
{
    public function handle(CourierProvider $provider, ?bool $active, bool $makeDefault): CourierProvider
    {
        return DB::transaction(static function () use ($provider, $active, $makeDefault): CourierProvider {
            // Lock every provider row in id order: the default flag spans rows.
            $all = CourierProvider::query()->orderBy('id')->lockForUpdate()->get();
            /** @var CourierProvider $locked */
            $locked = $all->firstOrFail(fn (CourierProvider $p): bool => $p->id === $provider->id);

            if ($active !== null) {
                $locked->is_active = $active;
            }
            if ($makeDefault) {
                $locked->is_active = true;
                foreach ($all as $other) {
                    if ($other->id !== $locked->id && $other->is_default) {
                        $other->is_default = false;
                        $other->save();
                    }
                }
                $locked->is_default = true;
            }
            $locked->save();

            return $locked->refresh();
        });
    }
}
