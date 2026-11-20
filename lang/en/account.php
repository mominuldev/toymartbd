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
    'password' => [
        'invalid' => 'That mobile number and password do not match. Try again, or sign in with an SMS code.',
        'current_invalid' => 'Your current password is not right. Forgot it? Reset it with an SMS code.',
    ],
    'register' => [
        'exists' => 'This mobile number is already registered. Sign in, or reset your password.',
    ],
    'address' => [
        'limit' => 'You can save up to :max addresses. Remove one first.',
    ],
    // My kids (P8 batch 3): first name/nickname + birthday, year optional.
    'child' => [
        'limit' => 'You can add up to :max children. Remove one first.',
        'birthday_invalid' => 'That birthday is not a real date. Check the day, month and year.',
    ],
    // Order-update channels on the profile page; security messages (sign-in codes) are never switchable.
    'notifications' => [
        'security_locked' => 'Sign-in codes are always sent; they cannot be turned off.',
    ],
    'wishlist' => [
        'limit' => 'Your wishlist is full (:max toys). Remove one first.',
        'unavailable' => 'This toy is not available.',
    ],
];
