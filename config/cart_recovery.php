<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Abandoned-cart recovery (P7, docs/api/cart-recovery.md)
|--------------------------------------------------------------------------
|
| One SMS reminder per abandoned cart, only with consent (a guest's explicit tick, or a
| signed-in customer's marketing preference — an explicit opt-out always wins), never on
| the checkout screen. Every value below is a starting PLACEHOLDER for the owner to tune.
|
*/

return [

    'enabled' => (bool) env('CART_RECOVERY_ENABLED', true),

    // A cart is abandoned this long after its last change (and after the contact was left).
    'after_minutes' => (int) env('CART_RECOVERY_AFTER_MINUTES', 60),

    // Older carts are not messaged at all (a reminder days later reads as spam).
    'max_age_hours' => (int) env('CART_RECOVERY_MAX_AGE_HOURS', 48),

    // At most one reminder per phone in this window, across all of that phone's carts.
    'phone_cooldown_hours' => (int) env('CART_RECOVERY_PHONE_COOLDOWN_HOURS', 72),

    // How long the restore link in the SMS works.
    'restore_ttl_days' => (int) env('CART_RECOVERY_RESTORE_TTL_DAYS', 7),

    // Carts below this goods value are not worth an SMS (decimal string; 0 = all).
    'min_cart_value' => env('CART_RECOVERY_MIN_CART_VALUE', '0.00'),

    // Public storefront origin the restore link points at (no trailing slash).
    'storefront_url' => env('CART_RECOVERY_STOREFRONT_URL', 'https://'.env('STORE_PUBLIC_DOMAIN', 'toymartbd.com')),

    // Messaging hours in Dhaka time: a cart abandoned at night waits for the morning run.
    'quiet_hours' => ['from' => '21:00', 'to' => '09:00'],

];
