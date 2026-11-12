<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Order risk (DATABASE-DESIGN §5.2, CLAUDE.md rules 23, 24b, 33b)
|--------------------------------------------------------------------------
|
| Used by App\Domain\Risk\Services\OrderRiskAssessor at checkout. The score is
| advisory: a risky order is put ON HOLD for a verification call, never cancelled.
|
| Launch scope (rule 33b): our own order history only — no courier fraud data yet.
| PROVISIONAL values: the owner has not set the threshold (UI-UX-DESIGN-SPEC §10).
|
*/

return [
    // Rule 33b: a first order from an unknown phone at or above this grand total is held
    // for verification regardless of score. Decimal string, BDT.
    'first_order_hold_threshold' => env('RISK_FIRST_ORDER_HOLD_THRESHOLD', '3000.00'),

    // §5.2 signal weights (only the signals we can compute at launch are listed).
    'weights' => [
        'first_order' => 10,
    ],

    // §5.2 level thresholds: 0–39 low, 40–69 medium, 70–100 high. High is held.
    'levels' => [
        'medium' => 40,
        'high' => 70,
    ],
];
