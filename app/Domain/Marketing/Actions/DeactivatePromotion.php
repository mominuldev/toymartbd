<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Enums\PromotionType;
use App\Domain\Marketing\Events\PromotionChanged;
use App\Domain\Marketing\Models\Promotion;
use Illuminate\Support\Facades\DB;

/** Switch a promotion off (admin `DELETE`). Never hard-deleted: its usages carry limits and reports. Idempotent. */
final class DeactivatePromotion
{
    public function handle(Promotion $promotion): Promotion
    {
        return DB::transaction(function () use ($promotion): Promotion {
            $promotion = Promotion::query()->lockForUpdate()->findOrFail($promotion->id);
            if ($promotion->is_active) {
                $promotion->is_active = false;
                $promotion->save();
                PromotionChanged::dispatch($promotion->id, $promotion->type === PromotionType::FlashSale, SavePromotion::slugs($promotion));
            }

            return $promotion->load('flashItems');
        });
    }
}
