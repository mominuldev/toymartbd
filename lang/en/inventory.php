<?php

declare(strict_types=1);

/*
| Inventory strings returned by the API (stock adjustments). Admin UI copy lives in
| resources/js/admin/i18n/messages.inventory.ts.
*/
return [

    'adjust' => [
        'no_change' => 'This leaves the stock unchanged.',
        'only_in' => 'This reason can only add stock.',
        'only_out' => 'This reason can only remove stock.',
        'below_reserved' => 'Would leave less than the :reserved units reserved for open orders (:on_hand on hand).',
        'duplicate_variant' => 'Each variant can appear only once in an adjustment.',
        'quantity_or_counted' => 'Give either a change or a counted quantity, not both.',
    ],

];
