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
    // Admin → Integrations → Messaging: the owner's REVE sender is non-masking (2026-11-13).
    'sender_id_numeric' => 'সেন্ডার আইডি আপনার REVE নন-মাস্কিং নম্বর: শুধু সংখ্যা, 5 থেকে 15টি (যেমন 8809612345678, 01712345678 বা শর্ট কোড)। অক্ষর চলবে না।',

    // জন্মদিনের রিমাইন্ডার (P8 batch 3): সন্তানের জন্মদিনের আগে অভিভাবককে। NEW লেখা মালিকের রিভিউয়ের জন্য।
    'birthday' => [
        'child_possessive' => ':name-এর',
        'child_possessive_unnamed' => 'আপনার সন্তানের',
        // NEW
        'offer_sentence' => 'উপহার কোড :code: :offer ছাড়, :expiry পর্যন্ত।',
        'offer_percent' => ':value%',
        'offer_fixed' => 'BDT :value',
        // NEW
        'no_offer_whatsapp' => 'সব বয়সের জন্য উপহার আছে আমাদের কাছে।',
    ],

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
