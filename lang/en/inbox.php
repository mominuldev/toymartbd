<?php

declare(strict_types=1);

// Unified inbox (P10): text the server composes and SENDS to a customer (quick actions).
return [
    'errors' => [
        'outside_window' => 'The 24-hour customer-service window has closed. Only an approved template can be sent now.',
        'reply_closed' => 'This conversation can no longer be replied to: Messenger allows a reply only within 24 hours of the customer\'s last message (7 days with the human-agent tag, when it is switched on).',
        'template_unavailable' => 'This template is not approved for sending. Pick one from the list.',
        'template_parameters' => 'This template needs exactly :count values.',
        'template_whatsapp_only' => 'Templates can be sent only in a WhatsApp conversation.',
        'template_window_open' => 'The 24-hour window is open. Send a normal reply instead.',
        'product_not_shareable' => 'Only an active product on the online store can be shared.',
        'order_not_shareable' => "Only an order of this conversation's customer can be shared.",
    ],
    'quick' => [
        'product_card' => ":name\n:url",
        'tracking_link' => 'Track your order :number here: :url',
    ],
];
