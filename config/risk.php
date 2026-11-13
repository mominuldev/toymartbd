<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Order risk (DATABASE-DESIGN §5.2, CLAUDE.md rules 23, 24b, 33b)
|--------------------------------------------------------------------------
|
| DEFAULTS only. The live values are the owner's "Risk rules" form (settings key
| `risk.rules`, App\Domain\Risk\Services\RiskSettings); these apply until it is saved.
| The score is advisory: a risky order is put ON HOLD for a call, never cancelled.
|
| Launch scope (rule 33b): our own order history only — no courier fraud data yet.
| PROVISIONAL values: the owner has not set the threshold (UI-UX-DESIGN-SPEC §10).
|
*/

return [
    // Rule 33b: a first order from an unknown phone at or above this grand total is held
    // for verification regardless of score. Decimal string, BDT.
    'first_order_hold_threshold' => env('RISK_FIRST_ORDER_HOLD_THRESHOLD', '3000.00'),

    // §5.2 signal weights (App\Domain\Risk\Enums\RiskFactor). Must total ≤ 100 so the
    // breakdown always sums to the score.
    'weights' => [
        'cancellation_rate' => 25,
        'courier_return_rate' => 25,
        'failed_delivery' => 15,
        'first_order' => 10,
        'value_above_norm' => 10,
        'address_quality' => 5,
        'rapid_repeat' => 5,
    ],

    // `value_above_norm`: the order is more than this multiple of the phone's average
    // delivered order. `rapid_repeat`: another order from the phone within this many minutes.
    'value_norm_multiplier' => '2',
    'rapid_repeat_minutes' => 10,

    // §5.2 level thresholds: 0–39 low, 40–69 medium, 70–100 high. High is held.
    'levels' => [
        'medium' => 40,
        'high' => 70,
    ],
];
