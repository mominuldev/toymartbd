<?php

declare(strict_types=1);

// Online payment messages (P4-PAY-02/04). Western numerals in both locales (rule 36).
return [
    'not_allowed' => [
        'not_online' => 'This order is paid on delivery.',
        'gateway_unavailable' => 'Online payment is not available right now. You can pay cash on delivery instead.',
        'already_paid' => 'This order is already paid.',
        'order_closed' => 'This order can no longer be paid online.',
        'under_review' => 'We are checking a payment on this order. We will call you shortly.',
        'too_many_attempts' => 'Too many payment attempts for this order. Please contact us.',
    ],
    'verify' => [
        'not_online' => 'Only an online payment can be re-checked with its gateway.',
    ],
];
