<?php

declare(strict_types=1);

// Storefront customer account (P4-STO-11). Numerals stay Western in both locales (rule 36).
return [
    'otp' => [
        'sms' => 'Toy Mart BD: your sign-in code is :code. It expires in :minutes minutes. Never share it — we will never ask for it.',
        'cooldown' => 'Please wait :seconds seconds before asking for another code.',
        'invalid' => 'That code is not right, or it has expired. Check the SMS or ask for a new code.',
        'burned' => 'Too many wrong tries. Ask for a new code.',
        'format' => 'Enter the 6-digit code from the SMS.',
        'blocked' => 'This account cannot sign in. Please call us for help.',
    ],
    'address' => [
        'limit' => 'You can save up to :max addresses. Remove one first.',
    ],
    'wishlist' => [
        'limit' => 'Your wishlist is full (:max toys). Remove one first.',
        'unavailable' => 'This toy is not available.',
    ],
];
