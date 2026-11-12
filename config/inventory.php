<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Inventory operations (P5-INV)
|--------------------------------------------------------------------------
|
| Every threshold below is a PLACEHOLDER until the owner sets it
| (CLAUDE.md "Known gaps"). They are business policy, not engineering facts.
|
*/

return [

    // A stock adjustment needs approval (inventory.approve_adjustments) when it moves
    // MORE than either figure, summed over its lines: units changed (absolute), or their
    // value at the location's weighted-average cost, in BDT. At or below both it applies
    // at once. A requester who holds the approval permission applies it directly
    // (recorded as approved by them) — owner decision pending on whether managers may
    // self-approve.
    'adjustment_approval' => [
        // PLACEHOLDER
        'max_units' => (int) env('INVENTORY_ADJUSTMENT_APPROVAL_UNITS', 20),
        // PLACEHOLDER
        'max_value' => (string) env('INVENTORY_ADJUSTMENT_APPROVAL_VALUE', '5000.00'),
    ],

    'alerts' => [
        // The daily low-stock digest to managers (SYSTEM-ARCHITECTURE §6 scheduled tasks:
        // 09:00 Asia/Dhaka).
        'digest_time' => '09:00',
        // Rows listed per section in the digest mail; the counts are always complete.
        'digest_rows' => 25,
    ],

];
