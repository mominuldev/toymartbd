<?php

declare(strict_types=1);

// NEEDS NATIVE-SPEAKER REVIEW (CLAUDE.md rule 35). Keys mirror lang/en/cart.php.
// Numerals stay Western (rule 36).
return [

    'stock' => [
        'out_of_stock' => 'দুঃখিত, পণ্যটি স্টকে নেই।',
        'only_left' => 'স্টকে মাত্র :availableটি আছে।',
    ],

    'quantity' => [
        'max' => 'একটি পণ্য সর্বোচ্চ :maxটি যোগ করা যাবে।',
    ],

    'lines' => [
        'max' => 'কার্টে সর্বোচ্চ :maxটি ভিন্ন পণ্য রাখা যাবে।',
    ],

    'variant' => [
        'unavailable' => 'পণ্যটি আর পাওয়া যাচ্ছে না।',
    ],

    'coupon' => [
        'not_found' => 'কুপন কোডটি সঠিক নয়।',
        'inactive' => 'কুপনটি এখন আর চালু নেই।',
        'not_started' => 'কুপনটি এখনো চালু হয়নি।',
        'expired' => 'কুপনটির মেয়াদ শেষ হয়ে গেছে।',
        'minimum_not_met' => 'এই কুপন ব্যবহার করতে কমপক্ষে ৳:minimum কেনাকাটা করুন।',
        'usage_limit_reached' => 'কুপনটির ব্যবহারের সীমা শেষ হয়ে গেছে।',
        'customer_limit_reached' => 'আপনি এই কুপনটি আগেই ব্যবহার করেছেন।',
        'not_for_customer' => 'এই কুপনটি অন্য একজন গ্রাহকের অ্যাকাউন্টের জন্য।',
        'not_applicable' => 'কার্টের পণ্যগুলোতে এই কুপন প্রযোজ্য নয়।',
        'first_order_only' => 'এই কুপন শুধু প্রথম অর্ডারের জন্য।',
        'not_combinable' => 'এই পণ্যগুলোতে থাকা অফারের সাথে এই কুপন একসাথে ব্যবহার করা যাবে না।',
    ],

    'promotion' => [
        'unavailable' => '":name" অফারটি এইমাত্র শেষ হয়ে গেছে। নতুন মোট দাম দেখে নিন।',
        'flash_sold_out' => 'এই পণ্যের ফ্ল্যাশ সেলের বরাদ্দ এইমাত্র শেষ হয়ে গেছে। নতুন মোট দাম দেখে নিন।',
    ],

    'recovery' => [
        'link_invalid' => 'এই কার্ট লিংকের মেয়াদ শেষ বা আগেই ব্যবহার করা হয়েছে।',
    ],

];
