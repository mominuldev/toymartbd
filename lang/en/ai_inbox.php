<?php

declare(strict_types=1);

// Inbox › Suggested reply (Figma 539:83211): the "Based on" chips under an AI draft.
// Digits stay Western in both languages (rule 36).
return [
    'based_on' => [
        'order' => 'Order :number',
        'tracking' => ':courier · :status · :ago',
        'tracking_no_time' => ':courier · :status',
        'status_only' => ':status',
    ],
    'ago' => [
        'now' => 'just now',
        'minutes' => ':n min ago',
        'hours' => ':n h ago',
        'days' => ':n d ago',
    ],
    'shipment' => [
        'pending' => 'Not booked yet',
        'submitted' => 'Submitted',
        'booked' => 'Booked',
        'picked_up' => 'Picked up',
        'in_transit' => 'In transit',
        'out_for_delivery' => 'Out for delivery',
        'delivered' => 'Delivered',
        'partially_delivered' => 'Partly delivered',
        'returned' => 'Returned',
        'cancelled' => 'Cancelled',
        'on_hold' => 'On hold',
        'failed' => 'Booking failed',
    ],
    'order' => [
        'pending' => 'Pending',
        'on_hold' => 'On hold',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'ready_to_ship' => 'Ready to ship',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'returned' => 'Returned',
        'partially_returned' => 'Partly returned',
    ],
];
