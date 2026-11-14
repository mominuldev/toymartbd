<?php

declare(strict_types=1);

// Unified inbox (P10). NEEDS NATIVE-SPEAKER REVIEW. Digits stay Western (rule 36).
return [
    'errors' => [
        'outside_window' => '24 ঘণ্টার কাস্টমার-সার্ভিস উইন্ডো বন্ধ হয়ে গেছে। এখন শুধু অনুমোদিত টেমপ্লেট পাঠানো যাবে।',
        'product_not_shareable' => 'শুধু অনলাইন স্টোরে সক্রিয় পণ্যই শেয়ার করা যায়।',
        'order_not_shareable' => 'শুধু এই কথোপকথনের কাস্টমারের অর্ডারই শেয়ার করা যায়।',
    ],
    'quick' => [
        'product_card' => ":name\n:url",
        'tracking_link' => 'আপনার অর্ডার :number এখানে ট্র্যাক করুন: :url',
    ],
];
