<?php

declare(strict_types=1);

// Unified inbox (P10): text the server composes and SENDS to a customer (quick actions).
return [
    'errors' => [
        'outside_window' => 'The 24-hour customer-service window has closed. Only an approved template can be sent now.',
        'product_not_shareable' => 'Only an active product on the online store can be shared.',
        'order_not_shareable' => "Only an order of this conversation's customer can be shared.",
    ],
    'quick' => [
        'product_card' => ":name\n:url",
        'tracking_link' => 'Track your order :number here: :url',
    ],
];
