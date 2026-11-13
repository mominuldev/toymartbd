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

    'approval' => [
        'self' => 'Someone other than the person who requested this adjustment must approve it.',
    ],

    'transfer' => [
        'exceeds_available' => 'Only :available available to transfer at the source (reserved units stay for their orders).',
        'same_location' => 'Choose a destination different from the source.',
        'duplicate_variant' => 'Each variant can appear only once in a transfer.',
    ],

    'mail' => [
        'digest_subject' => 'Stock digest for :date',
        'digest_preheader' => ':out out of stock, :low low on stock right now.',
        'digest_intro' => 'Your daily stock digest for :date (Dhaka time).',
        'raised_on' => 'New on :date',
        'open_now' => 'Open now',
        'kind' => [
            'out_of_stock' => 'Out of stock',
            'low_stock' => 'Low stock',
            'drift' => 'Ledger drift',
            'ledger_drift' => 'Ledger drift',
            'reserved_drift' => 'Reserved drift',
        ],
        'pending_adjustments' => 'Adjustments waiting for approval',
        'col_product' => 'Product',
        'col_location' => 'Location',
        'col_available' => 'Available',
        'col_reorder' => 'Reorder at',
        'col_expected' => 'Ledger',
        'col_actual' => 'Level',
        'new' => 'new',
        'drift_heading' => 'Stock ledger drift is open',
        'drift_subject' => 'Stock reconciliation found :count drift(s)',
        'drift_preheader' => ':count drift alert(s) open. Nothing was corrected automatically.',
        'drift_intro' => 'Last night\'s stock reconciliation found :count new or changed difference(s) between the stock ledger and the stock levels.',
        'drift_body' => 'Nothing was corrected automatically: drift means a code path skipped the ledger. Investigate, correct with a stock adjustment if needed, then acknowledge the alert.',
        'open_inventory' => 'Open inventory',
        'open_alerts' => 'Open stock alerts',
        'footer' => 'Toy Mart BD admin · sent to managers',
    ],

];
