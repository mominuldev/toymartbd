<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The nightly reconciliation found new or changed drift between the stock ledger and the
 * levels (DATABASE-DESIGN §7). Notification mails the managers; nothing corrects it.
 * Carries plain rows so a listener needs nothing from Inventory.
 */
final readonly class StockDriftDetected
{
    use Dispatchable;

    /**
     * @param  list<array{alert_id: int, kind: string, variant_id: int, sku: string, product_name: string, location_code: string, expected: int, actual: int}>  $drifts
     */
    public function __construct(
        public CarbonImmutable $checkedAt,
        public array $drifts,
        public int $openDriftCount,
    ) {}
}
