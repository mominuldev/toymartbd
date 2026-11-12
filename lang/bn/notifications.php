<?php

declare(strict_types=1);

/*
| গ্রাহকের নোটিফিকেশনে বসানো সার্ভার-রেন্ডার করা অংশ (P4-NOT-01..03)। সংখ্যা সবসময়
| পশ্চিমা অঙ্কে (rule 36)। `delivery_window` ও `shipping_eta` PLACEHOLDER।
*/
return [
    'delivery_window' => '2-3 দিনে',
    'shipping_eta' => '1-3 দিন',
    'courier_fallback' => 'কুরিয়ার',
    'consignment_pending' => 'শীঘ্রই জানানো হবে',
    'refund_sentence' => 'আপনার দেওয়া BDT :amount ফেরত দেওয়া হবে।',

    'mail' => [
        'greeting' => ':name,',
        'order_number' => 'অর্ডার',
        'items' => 'পণ্য',
        'quantity' => 'পরিমাণ',
        'subtotal' => 'পণ্যের মোট দাম',
        'delivery' => 'ডেলিভারি চার্জ',
        'discount' => 'ছাড়',
        'total' => 'সর্বমোট',
        'due' => 'ডেলিভারির সময় দিতে হবে',
        'track' => 'অর্ডার ট্র্যাক করুন',
        'help' => 'কোনো প্রশ্ন? :hotline নম্বরে ফোন করুন।',
        'footer' => 'টয় মার্ট বিডিতে অর্ডার করেছেন বলে এই ইমেইল পাঠানো হয়েছে।',
    ],
];
