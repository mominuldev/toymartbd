<?php

declare(strict_types=1);

// Cart and coupon messages returned by the storefront API (P4-CRT-01/04). Keys mirror lang/bn/cart.php.
return [

    'stock' => [
        'out_of_stock' => 'Sorry, this item is out of stock.',
        'only_left' => 'Only :available left in stock.',
    ],

    'quantity' => [
        'max' => 'You can add up to :max of one item.',
    ],

    'lines' => [
        'max' => 'Your cart can hold up to :max different items.',
    ],

    'variant' => [
        'unavailable' => 'This item is no longer available.',
    ],

    'coupon' => [
        'not_found' => 'This coupon code is not valid.',
        'inactive' => 'This coupon is no longer active.',
        'not_started' => 'This coupon is not active yet.',
        'expired' => 'This coupon has expired.',
        'minimum_not_met' => 'Spend at least ৳:minimum to use this coupon.',
        'usage_limit_reached' => 'This coupon has been fully redeemed.',
        'customer_limit_reached' => 'You have already used this coupon.',
        'not_applicable' => 'This coupon does not apply to the items in your cart.',
        'first_order_only' => 'This coupon is for first orders only.',
    ],

];
