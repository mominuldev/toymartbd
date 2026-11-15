<?php

declare(strict_types=1);

// POS register messages returned by the admin API (Figma B · 33, docs/api/pos.md).
// Keys mirror lang/bn/pos.php.
return [

    'session_closed' => 'This drawer is closed. Open a new drawer to sell.',
    'register_busy' => 'This register already has an open drawer. Close it first.',
    'cashier_busy' => 'You already have a drawer open. Close it before opening another.',
    'location_not_selling' => 'Nothing is sold at this location. Pick an active branch.',
    'hold_resumed' => 'This held sale has already been taken back.',
    'cart_empty' => 'Add at least one product.',
    'not_at_counter' => 'This product is not sold at the counter.',
    'phone_for_discount' => 'Enter the customer\'s phone to give a discount — offer limits are counted per phone.',
    'walk_in_customer' => 'Walk-in customer',

    'stock' => [
        'only_here' => 'Only :available here.',
        'none_here' => 'None in stock at this branch.',
    ],

    'tender' => [
        'amount_positive' => 'Enter an amount above zero.',
        'reference_required' => 'Enter the transaction ID or approval code.',
        'one_cash' => 'Enter cash once.',
        'non_cash_over' => 'bKash, Nagad and card together cannot be more than the total — only cash gives change.',
        'short' => 'Add a payment.',
        'short_by' => '৳:amount is still due.',
        'one_due' => 'Enter due once.',
        'due_over' => 'bKash, Nagad, card and due together cannot be more than the total — only cash gives change.',
    ],

    // Due / baki (owner decision 2026-11-15).
    'due' => [
        'not_allowed' => 'You cannot sell on due. Ask a manager.',
        'needs_customer' => 'Due needs a known customer — add the phone and name.',
        'over_limit' => 'Over the due limit — ৳:available available.',
        'nothing_open' => 'This customer has no due to collect.',
        'not_a_payment' => 'Due cannot pay off due — take cash, bKash, Nagad or card.',
        'non_cash_over' => 'bKash, Nagad and card together cannot be more than the due of ৳:amount — only cash gives change.',
    ],
    'hold_discarded' => 'This held sale has been discarded.',
];
