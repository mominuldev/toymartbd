<?php

declare(strict_types=1);

/*
| মনিটরিং — মালিকের কাছে ব্যবসায়িক সতর্কবার্তা (P10)। NEEDS NATIVE-SPEAKER REVIEW. Digits stay Western (rule 36).
*/
return [

    'names' => [
        'no_orders' => 'কোনো অর্ডার নেই',
        'courier_booking_failures' => 'কুরিয়ার বুকিং ব্যর্থ',
        'failed_jobs' => 'ব্যাকগ্রাউন্ড কাজ ব্যর্থ',
        'courier_poll_silence' => 'কুরিয়ার স্ট্যাটাস সিঙ্ক বন্ধ',
        'courier_webhook_silence' => 'কুরিয়ার ওয়েবহুক আসছে না',
        'sms_failures' => 'এসএমএস ব্যর্থ',
        'backup_stale' => 'ব্যাকআপ বাকি',
        'restore_rehearsal_stale' => 'রিস্টোর মহড়া বাকি',
        'horizon_down' => 'কিউ ওয়ার্কার বন্ধ',
        'queue_depth' => 'কিউতে জমে থাকা কাজ',
        'disk_space' => 'ডিস্কে জায়গা কম',
    ],

    'severity' => [
        'warning' => 'সতর্কতা',
        'critical' => 'জরুরি',
    ],

    'summary' => [
        'ok' => 'ঠিক আছে।',
        'not_applicable' => 'প্রযোজ্য নয়: :reason।',
        'no_orders' => 'গত :hours ব্যবসায়িক ঘণ্টায় (ঢাকা সময় :since থেকে) কোনো অর্ডার আসেনি। চেকআউট কাজ করছে কি না দেখুন।',
        'courier_booking_failures' => 'গত :minutes মিনিটে :attempts টি কুরিয়ার বুকিংয়ের মধ্যে :failures টি ব্যর্থ (:rate%)।',
        'failed_jobs' => 'গত :minutes মিনিটে :count টি ব্যাকগ্রাউন্ড কাজ ব্যর্থ (সীমা :threshold)।',
        'courier_poll_silence' => ':providers চালান থাকা সত্ত্বেও :minutes মিনিটের বেশি সময় ধরে সফল উত্তর দেয়নি।',
        'courier_webhook_silence' => ':providers চালান থাকা সত্ত্বেও :hours ঘণ্টার বেশি সময় ধরে কোনো ওয়েবহুক পাঠায়নি।',
        'sms_failures' => 'গত :minutes মিনিটে :total টি এসএমএসের মধ্যে :failures টি ব্যর্থ (:rate%)। এসএমএস ব্যালান্স ও প্রোভাইডার দেখুন।',
        'backup_stale' => 'সর্বশেষ সফল ব্যাকআপ :age ঘণ্টা আগের (সীমা :hours ঘণ্টা)।',
        'backup_never' => 'কোনো সফল ব্যাকআপের রেকর্ড নেই (সীমা :hours ঘণ্টা)।',
        'restore_rehearsal_stale' => 'সর্বশেষ সফল রিস্টোর মহড়া :age দিন আগের (সীমা :days দিন)।',
        'restore_rehearsal_never' => 'কোনো সফল রিস্টোর মহড়ার রেকর্ড নেই (সীমা :days দিন)।',
        'horizon_down' => 'Horizon চলছে না: কিউয়ের কোনো কাজ (অর্ডার বার্তা, কুরিয়ার বুকিং, এসএমএস) হচ্ছে না।',
        'queue_depth' => 'কিউতে :threshold-এর বেশি কাজ জমে আছে: :queues।',
        'queue_unreachable' => 'কিউ ব্যাকএন্ডে সংযোগ করা যাচ্ছে না।',
        'disk_space' => 'অ্যাপ্লিকেশন ডিস্কে মাত্র :free_gb GB (:percent%) খালি।',
    ],

    'mail' => [
        'test_tag' => 'পরীক্ষা',
        'subject_firing' => ':severity সতর্কবার্তা: :name',
        'subject_reminder' => 'এখনও চালু (:severity): :name',
        'subject_resolved' => 'সমাধান হয়েছে: :name',
        'intro_firing' => 'একটি ব্যবসায়িক সতর্কবার্তা চালু হয়েছে।',
        'intro_reminder' => 'এই সতর্কবার্তা এখনও চালু আছে।',
        'intro_resolved' => 'এই সতর্কবার্তার সমাধান হয়েছে।',
        'test_note' => 'এটি `php artisan toymart:alerts:test` থেকে পাঠানো একটি পরীক্ষামূলক বার্তা। কোনো সমস্যা নেই।',
        'checked_at' => 'যাচাইয়ের সময় (UTC)',
        'runbook' => 'করণীয়: docs/ops/observability.md, "Business alerts"।',
        'footer' => 'টয় মার্ট বিডি মনিটরিং। প্রাপক বদলাতে MONITORING_ALERT_EMAILS / MONITORING_ALERT_PHONES ব্যবহার করুন।',
    ],

    'sms' => ':tagToy Mart BD :kind :name: :summary',

];
