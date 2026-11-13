<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Returns and refunds (DATABASE-DESIGN §4.7, PRD O-21)
|--------------------------------------------------------------------------
|
| Used by App\Domain\Ordering\Actions\Returns\* and App\Policies\RefundPolicy.
|
| The refund approval threshold is the owner's decision (2026-11-12: ৳2,000). The
| return window (7 vs 10 days) is a PLACEHOLDER pending legal advice (PRD §7 item 9c).
|
*/

return [
    // A refund whose amount is ABOVE this needs refunds.approve (manager, accountant,
    // owner). At or below it, a staff member holding refunds.create (order operator) may
    // approve. Decimal string, BDT. Owner decision 2026-11-12: ৳2,000.
    'approval_threshold' => env('REFUND_APPROVAL_THRESHOLD', '2000.00'),

    // Days after delivery within which a return may be requested. PLACEHOLDER — the
    // Digital Commerce Operation Guidelines figure (7 vs 10) needs a lawyer. Advisory
    // only for now: staff may still record a return outside it (the screen flags it).
    'window_days' => (int) env('RETURN_WINDOW_DAYS', 7),
];
