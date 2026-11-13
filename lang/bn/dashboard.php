<?php

declare(strict_types=1);

// ড্যাশবোর্ড এক্সপোর্ট (Phase 8)। "দেওয়া" = চাহিদা, "স্বীকৃত" = ডেলিভারিতে লেজারের আয়,
// "নগদ" = কুরিয়ারের পাঠানো COD (rule 10)। সংখ্যা সবসময় পশ্চিমা অঙ্কে, 0–9 (rule 36)।
// NEEDS NATIVE-SPEAKER REVIEW.
return [
    'sheet' => [
        'summary' => 'সারসংক্ষেপ',
        'trend' => 'প্রবণতা',
        'status' => 'অবস্থা অনুযায়ী অর্ডার (এখন)',
        'categories' => 'ক্যাটাগরি অনুযায়ী আয়',
        'best_sellers' => 'সেরা বিক্রিত (দেওয়া)',
        'low_stock' => 'কম স্টক (এখন)',
    ],
    'metric' => [
        'range' => 'সময়কাল (ঢাকা তারিখ)',
        'previous_range' => 'তুলনা (ঢাকা তারিখ)',
        'granularity' => 'প্রবণতার ভাগ',
        'exported_at' => 'এক্সপোর্টের সময় (ঢাকা)',
        'orders_placed' => 'দেওয়া অর্ডার (পরে বাতিলসহ)',
        'placed_value' => 'দেওয়া অর্ডারের মূল্য (পরে বাতিলসহ)',
        'average_order_value' => 'গড় অর্ডার মূল্য (দেওয়া)',
        'recognised_revenue' => 'স্বীকৃত নিট আয় (ডেলিভারি হওয়া)',
        'gross_profit' => 'মোট মুনাফা (স্বীকৃত)',
        'gross_margin_percent' => 'মোট মার্জিন % (স্বীকৃত)',
        'net_profit' => 'নিট মুনাফা (স্বীকৃত)',
        'net_margin_percent' => 'নিট মার্জিন % (স্বীকৃত)',
        'cash_collected' => 'আদায় হওয়া নগদ (কুরিয়ারের পাঠানো COD) — নগদ, আয় নয়',
        'customers' => 'অর্ডার দেওয়া গ্রাহক',
        'new_customers' => 'নতুন গ্রাহক (প্রথম অর্ডার এই সময়ে)',
        'returning_customers' => 'পুরনো গ্রাহক',
        'low_stock' => 'কম স্টকের ভ্যারিয়েন্ট (এখন)',
        'out_of_stock' => 'স্টকে নেই এমন ভ্যারিয়েন্ট (এখন)',
        'open_orders' => 'খোলা অর্ডার, এখনো আয় নয় (এখন)',
        'open_orders_value' => 'খোলা অর্ডারের মূল্য, এখনো আয় নয় (এখন)',
    ],
    'granularity' => [
        'day' => 'দিন',
        'week' => 'সপ্তাহ (শনিবার–শুক্রবার)',
        'month' => 'মাস',
    ],
    'status' => [
        'pending' => 'অপেক্ষমাণ',
        'on_hold' => 'স্থগিত',
        'confirmed' => 'নিশ্চিত',
        'processing' => 'প্রক্রিয়াধীন',
        'ready_to_ship' => 'পাঠানোর জন্য প্রস্তুত',
        'shipped' => 'পাঠানো হয়েছে',
        'delivered' => 'ডেলিভারি হয়েছে',
        'completed' => 'সম্পন্ন',
        'cancelled' => 'বাতিল',
        'returned' => 'ফেরত',
        'partially_returned' => 'আংশিক ফেরত',
    ],
    'other_categories' => 'অন্যান্য ক্যাটাগরি',
    'no_category' => 'কোনো ক্যাটাগরি নেই',
    'total' => 'মোট',
    'all_products' => 'এই সময়ে দেওয়া সব পণ্য',
];
