<?php

declare(strict_types=1);

/*
| SEO module (docs/api/seo.md). Bangla copy written by the developer — needs owner review
| (PRD: human-written Bangla copy belongs to the owner).
*/
return [
    'site_name' => 'Toy Mart BD',
    'default_description' => [
        'category' => 'Toy Mart BD-তে অনলাইনে :name খেলনা কিনুন। আসল পণ্য, সারা বাংলাদেশে ক্যাশ অন ডেলিভারি।',
        'brand' => 'Toy Mart BD-তে আসল :name খেলনা কিনুন। সারা বাংলাদেশে ক্যাশ অন ডেলিভারি।',
        'page' => ':name — Toy Mart BD।',
        'blog_post' => ':name — Toy Mart BD খেলনা গাইড।',
        'blog_category' => ':name — Toy Mart BD-এর লেখা ও কেনার গাইড।',
        'blog_tag' => 'Toy Mart BD খেলনা গাইডে :name নিয়ে লেখা।',
        'blog_author' => 'Toy Mart BD খেলনা গাইডে :name-এর লেখা।',
        'product' => 'Toy Mart BD-তে অনলাইনে :name কিনুন। সারা বাংলাদেশে ক্যাশ অন ডেলিভারি।',
    ],
    'redirect' => [
        'from_invalid' => 'উৎস অবশ্যই / দিয়ে শুরু হওয়া একটি পাথ হতে হবে (কোয়েরি স্ট্রিং বা ফ্র্যাগমেন্ট ছাড়া)।',
        'to_invalid' => 'লক্ষ্য অবশ্যই / দিয়ে শুরু হওয়া পাথ বা https:// URL হতে হবে।',
        'same' => 'একটি রিডাইরেক্ট নিজের দিকেই নির্দেশ করতে পারে না।',
        'live' => ':path একটি চালু পেজ। আগে ওই পেজটি বদলান বা আর্কাইভ করুন; রিডাইরেক্ট এটিকে লুকিয়ে ফেলবে।',
        'reserved' => ':path স্টোরফ্রন্টের নিজস্ব রুট, এটি রিডাইরেক্ট করা যাবে না।',
        'loop' => 'এই রিডাইরেক্ট একটি লুপ তৈরি করবে: :chain।',
        'duplicate' => ':path থেকে একটি রিডাইরেক্ট আগে থেকেই আছে।',
        'csv_header' => 'প্রথম সারিটি অবশ্যই from_path,to_path,status_code[,note] হেডার হতে হবে।',
        'csv_empty' => 'ফাইলে কোনো রিডাইরেক্ট সারি নেই।',
        'csv_too_many' => 'প্রতি ইমপোর্টে সর্বোচ্চ :max সারি।',
        'csv_row' => 'সারি :line: :message',
        'csv_status' => 'status_code অবশ্যই 301 বা 302 হতে হবে।',
    ],
    'robots' => [
        'unknown_directive' => 'লাইন :line: ":directive" সমর্থিত ডিরেক্টিভ নয় (User-agent, Allow, Disallow, Sitemap, Crawl-delay)।',
        'malformed' => 'লাইন :line: "Directive: value" আকারে হতে হবে।',
        'rule_before_agent' => 'লাইন :line: প্রতিটি নিয়মের আগে একটি User-agent লাইন থাকতে হবে।',
        'bad_path' => 'লাইন :line: নিয়মের পাথ খালি অথবা / বা * দিয়ে শুরু হতে হবে।',
        'bad_sitemap' => 'লাইন :line: Sitemap অবশ্যই সম্পূর্ণ https:// বা http:// URL হতে হবে।',
        'bad_delay' => 'লাইন :line: Crawl-delay সেকেন্ডের একটি সংখ্যা হতে হবে।',
        'blocks_everything' => 'লাইন :line: সব ক্রলারের জন্য "Disallow: /" পুরো দোকানকে সার্চ থেকে সরিয়ে দেবে।',
    ],
];
