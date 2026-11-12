<?php

declare(strict_types=1);

// Checkout, confirmation and tracking messages returned by the storefront API
// (P4-ORD-02, P4-STO-09/10, docs/api/checkout.md). Keys mirror lang/bn/checkout.php.
return [

    'cart_empty' => 'Your cart is empty.',
    'not_serviced' => "We don't deliver to this area yet.",
    'price_changed' => 'The total has changed to ৳:total. Please review your order and place it again.',

    'phone' => [
        'invalid' => 'Enter an 11-digit mobile number starting with 01 (013–019).',
    ],

    'upazila' => [
        'required' => 'Please select an upazila / thana.',
        'mismatch' => 'This upazila is not in the selected district.',
    ],

    'division' => [
        'mismatch' => 'This district is not in the selected division.',
    ],

    'payment_method' => [
        'unsupported' => 'This payment method is not available.',
    ],

    'track' => [
        'phone' => 'Enter the last 4 digits of your phone number.',
        'number' => 'Enter your order number, e.g. TMB-26-00042.',
        'not_found' => "That order number and those digits don't match. Please check the number in your SMS.",
    ],

];
