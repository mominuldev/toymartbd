<?php

declare(strict_types=1);

/*
| Server-rendered fragments that go into customer notifications (P4-NOT-01..03).
| English SMS text must stay GSM-7 clean: no ৳, no en-dash, no curly quotes.
| `delivery_window` and `shipping_eta` are PLACEHOLDERS until Fulfilment computes a
| per-order ETA; `courier_fallback` is used until shipments carry a courier name.
*/
return [
    'delivery_window' => 'in 2-3 days',
    'shipping_eta' => '1-3 days',
    'courier_fallback' => 'our courier',
    'consignment_pending' => 'pending',
    'refund_sentence' => 'The BDT :amount you paid will be refunded.',
    // Admin → Integrations → Messaging: the owner's REVE sender is non-masking (2026-11-13).
    'sender_id_numeric' => 'The sender ID is your REVE non-masking number: digits only, 5 to 15 of them (e.g. 8809612345678, 01712345678 or a short code). Letters are not allowed.',

    'mail' => [
        'greeting' => 'Hello :name,',
        'order_number' => 'Order',
        'items' => 'Items',
        'quantity' => 'Qty',
        'subtotal' => 'Subtotal',
        'delivery' => 'Delivery',
        'discount' => 'Discount',
        'total' => 'Total',
        'due' => 'Due on delivery',
        'track' => 'Track your order',
        'help' => 'Questions? Call :hotline.',
        'footer' => 'You are receiving this because you placed an order with Toy Mart BD.',
    ],
];
