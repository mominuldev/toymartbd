<?php

declare(strict_types=1);

/*
| Purchasing strings returned by the API. Admin UI copy lives in
| resources/js/admin/i18n/messages.purchasing.ts.
*/
return [

    'conflict' => [
        'not_editable' => 'Purchase order :number is :status; only a draft can be edited.',
        'transition' => 'Purchase order :number cannot move from :from to :to.',
        'not_receivable' => 'Purchase order :number is :status; stock can only be received against an ordered purchase order.',
        'already_received' => 'Purchase order :number has stock received against it and can no longer be cancelled.',
    ],

    'lines' => [
        'discount_exceeds' => 'The discount is larger than the line value.',
        'duplicate_variant' => 'Each variant can appear only once on a purchase order.',
    ],

    'receive' => [
        'unknown_line' => 'This line is not on the purchase order.',
        'duplicate_line' => 'Each line can appear only once in a receipt.',
        'negative' => 'Enter zero or more units.',
        'over_receipt' => 'Only :outstanding units are still outstanding on this line.',
        'nothing' => 'Enter the units received on at least one line.',
        'no_weight' => 'These variants have no weight recorded; split the costs by value or quantity instead.',
        'location_inactive' => 'Choose an active stock location.',
    ],

    'payment' => [
        'positive' => 'The payment must be more than zero.',
        'other_supplier' => 'This purchase order belongs to another supplier.',
        'not_payable' => 'A :status purchase order cannot take a payment.',
    ],

    'supplier' => [
        'inactive' => 'This supplier is archived.',
    ],

];
