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
    // Admin → Integrations → Messaging: the WhatsApp `cloud` driver sends on the Inbox's number.
    'whatsapp_cloud_needs_inbox' => 'Connect WhatsApp on the Inbox channels card first: the Phone number ID and access token must be saved there.',
    // Message templates › Sync from Meta (GET /{waba-id}/message_templates).
    'whatsapp_sync_not_configured' => 'Enter the WhatsApp Business Account ID and access token on the Inbox channels card first.',
    'whatsapp_sync_failed' => 'Meta refused the template list (:code). Check the WhatsApp Business Account ID and the access token.',

    // Birthday reminders (P8 batch 3): to the parent, before a child's birthday. Copy marked
    // NEW is for the owner's review (the batch-2 birthday copy was approved; this adapts it).
    'birthday' => [
        'child_possessive' => ':name\'s',
        'child_possessive_unnamed' => 'your child\'s',
        // NEW — the offer line in the reminder (SMS: GSM-7 only, no taka sign).
        'offer_sentence' => 'Gift code :code: :offer off, valid till :expiry.',
        'offer_percent' => ':value%',
        'offer_fixed' => 'BDT :value',
        // NEW — WhatsApp needs a non-empty parameter when there is no offer.
        'no_offer_whatsapp' => 'We have gifts for every age.',
    ],

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
        'footer' => 'You are receiving this because you placed an order with Smart Junior.',
    ],
];
