<?php

declare(strict_types=1);

// Unified inbox (P10). NEEDS NATIVE-SPEAKER REVIEW. Digits stay Western (rule 36).
return [
    'errors' => [
        'outside_window' => '24 ঘণ্টার কাস্টমার-সার্ভিস উইন্ডো বন্ধ হয়ে গেছে। এখন শুধু অনুমোদিত টেমপ্লেট পাঠানো যাবে।',
        'reply_closed' => 'এই কথোপকথনে আর উত্তর দেওয়া যাবে না: Messenger-এ কাস্টমারের শেষ মেসেজের 24 ঘণ্টার মধ্যে উত্তর দেওয়া যায় (হিউম্যান-এজেন্ট ট্যাগ চালু থাকলে 7 দিন)।',
        'template_unavailable' => 'এই টেমপ্লেটটি পাঠানোর জন্য অনুমোদিত নয়। তালিকা থেকে একটি বেছে নিন।',
        'template_parameters' => 'এই টেমপ্লেটে ঠিক :countটি মান লাগবে।',
        'template_whatsapp_only' => 'টেমপ্লেট শুধু WhatsApp কথোপকথনে পাঠানো যায়।',
        'template_window_open' => '24 ঘণ্টার উইন্ডো খোলা আছে। সাধারণ উত্তর পাঠান।',
        'product_not_shareable' => 'শুধু অনলাইন স্টোরে সক্রিয় পণ্যই শেয়ার করা যায়।',
        'order_not_shareable' => 'শুধু এই কথোপকথনের কাস্টমারের অর্ডারই শেয়ার করা যায়।',
    ],
    'quick' => [
        'product_card' => ":name\n:url",
        'tracking_link' => 'আপনার অর্ডার :number এখানে ট্র্যাক করুন: :url',
    ],
];
