<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Models\AdSpend;

/** The audit payload of an ad spend: plain strings, the month as Y-m. */
final class AdSpendAudit
{
    /** @return array{campaign: string, source: string|null, month: string, amount: string, note: string|null} */
    public static function snapshot(AdSpend $spend): array
    {
        return [
            'campaign' => $spend->campaign,
            'source' => $spend->source,
            'month' => $spend->month->format('Y-m'),
            'amount' => $spend->amount->toDecimal(),
            'note' => $spend->note,
        ];
    }
}
