<?php

declare(strict_types=1);

// Inbox › Suggested reply (Figma 539:83211): the "Based on" chips under an AI draft.
// NEEDS NATIVE-SPEAKER REVIEW. Digits stay Western (rule 36).
return [
    'based_on' => [
        'order' => 'অর্ডার :number',
        'tracking' => ':courier · :status · :ago',
        'tracking_no_time' => ':courier · :status',
        'status_only' => ':status',
    ],
    'ago' => [
        'now' => 'এইমাত্র',
        'minutes' => ':n মিনিট আগে',
        'hours' => ':n ঘণ্টা আগে',
        'days' => ':n দিন আগে',
    ],
    'shipment' => [
        'pending' => 'এখনো বুক হয়নি',
        'submitted' => 'জমা দেওয়া হয়েছে',
        'booked' => 'বুক হয়েছে',
        'picked_up' => 'পিকআপ হয়েছে',
        'in_transit' => 'পথে আছে',
        'out_for_delivery' => 'ডেলিভারির জন্য বের হয়েছে',
        'delivered' => 'ডেলিভারি হয়েছে',
        'partially_delivered' => 'আংশিক ডেলিভারি',
        'returned' => 'ফেরত এসেছে',
        'cancelled' => 'বাতিল',
        'on_hold' => 'স্থগিত',
        'failed' => 'বুকিং ব্যর্থ',
    ],
    'order' => [
        'pending' => 'অপেক্ষমাণ',
        'on_hold' => 'স্থগিত',
        'confirmed' => 'নিশ্চিত',
        'processing' => 'প্রস্তুত হচ্ছে',
        'ready_to_ship' => 'পাঠানোর জন্য প্রস্তুত',
        'shipped' => 'পাঠানো হয়েছে',
        'delivered' => 'ডেলিভারি হয়েছে',
        'completed' => 'সম্পন্ন',
        'cancelled' => 'বাতিল',
        'returned' => 'ফেরত',
        'partially_returned' => 'আংশিক ফেরত',
    ],
];
