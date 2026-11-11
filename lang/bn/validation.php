<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Validation Language Lines — Bangla
|--------------------------------------------------------------------------
|
| NEEDS NATIVE-SPEAKER REVIEW before launch (CLAUDE.md rule 35; machine
| translation is not accepted for customer copy). Rules not listed here fall
| back to lang/en/validation.php via app.fallback_locale.
|
| Digits in substituted values (:max, :min, :size …) stay Western (rule 36).
|
*/

return [

    'accepted' => ':attribute গ্রহণ করতে হবে।',
    'active_url' => ':attribute একটি সঠিক URL হতে হবে।',
    'after' => ':attribute অবশ্যই :date-এর পরের তারিখ হতে হবে।',
    'after_or_equal' => ':attribute অবশ্যই :date অথবা তার পরের তারিখ হতে হবে।',
    'alpha' => ':attribute-এ শুধু অক্ষর থাকতে পারবে।',
    'alpha_dash' => ':attribute-এ শুধু অক্ষর, সংখ্যা, ড্যাশ ও আন্ডারস্কোর থাকতে পারবে।',
    'alpha_num' => ':attribute-এ শুধু অক্ষর ও সংখ্যা থাকতে পারবে।',
    'array' => ':attribute একটি তালিকা হতে হবে।',
    'before' => ':attribute অবশ্যই :date-এর আগের তারিখ হতে হবে।',
    'before_or_equal' => ':attribute অবশ্যই :date অথবা তার আগের তারিখ হতে হবে।',
    'between' => [
        'array' => ':attribute-এ :min থেকে :max টি আইটেম থাকতে হবে।',
        'file' => ':attribute-এর আকার :min থেকে :max কিলোবাইটের মধ্যে হতে হবে।',
        'numeric' => ':attribute অবশ্যই :min থেকে :max-এর মধ্যে হতে হবে।',
        'string' => ':attribute অবশ্যই :min থেকে :max অক্ষরের মধ্যে হতে হবে।',
    ],
    'boolean' => ':attribute অবশ্যই হ্যাঁ অথবা না হতে হবে।',
    'confirmed' => ':attribute-এর নিশ্চিতকরণ মিলছে না।',
    'current_password' => 'পাসওয়ার্ডটি সঠিক নয়।',
    'date' => ':attribute একটি সঠিক তারিখ হতে হবে।',
    'date_equals' => ':attribute অবশ্যই :date তারিখ হতে হবে।',
    'date_format' => ':attribute অবশ্যই :format ফরম্যাটে হতে হবে।',
    'decimal' => ':attribute-এ দশমিকের পরে :decimal ঘর থাকতে হবে।',
    'different' => ':attribute এবং :other অবশ্যই ভিন্ন হতে হবে।',
    'digits' => ':attribute অবশ্যই :digits অঙ্কের হতে হবে।',
    'digits_between' => ':attribute অবশ্যই :min থেকে :max অঙ্কের মধ্যে হতে হবে।',
    'distinct' => ':attribute-এ একই মান একাধিকবার দেওয়া হয়েছে।',
    'email' => ':attribute একটি সঠিক ইমেইল ঠিকানা হতে হবে।',
    'ends_with' => ':attribute অবশ্যই এগুলোর কোনো একটি দিয়ে শেষ হতে হবে: :values।',
    'enum' => 'নির্বাচিত :attribute সঠিক নয়।',
    'exists' => 'নির্বাচিত :attribute সঠিক নয়।',
    'file' => ':attribute অবশ্যই একটি ফাইল হতে হবে।',
    'filled' => ':attribute-এ একটি মান দিতে হবে।',
    'gt' => [
        'array' => ':attribute-এ :value টির বেশি আইটেম থাকতে হবে।',
        'file' => ':attribute-এর আকার :value কিলোবাইটের বেশি হতে হবে।',
        'numeric' => ':attribute অবশ্যই :value-এর বেশি হতে হবে।',
        'string' => ':attribute অবশ্যই :value অক্ষরের বেশি হতে হবে।',
    ],
    'gte' => [
        'array' => ':attribute-এ অন্তত :value টি আইটেম থাকতে হবে।',
        'file' => ':attribute-এর আকার অন্তত :value কিলোবাইট হতে হবে।',
        'numeric' => ':attribute অবশ্যই :value অথবা তার বেশি হতে হবে।',
        'string' => ':attribute অবশ্যই অন্তত :value অক্ষরের হতে হবে।',
    ],
    'image' => ':attribute অবশ্যই একটি ছবি হতে হবে।',
    'in' => 'নির্বাচিত :attribute সঠিক নয়।',
    'integer' => ':attribute অবশ্যই একটি পূর্ণসংখ্যা হতে হবে।',
    'ip' => ':attribute একটি সঠিক IP ঠিকানা হতে হবে।',
    'json' => ':attribute একটি সঠিক JSON হতে হবে।',
    'lt' => [
        'array' => ':attribute-এ :value টির কম আইটেম থাকতে হবে।',
        'file' => ':attribute-এর আকার :value কিলোবাইটের কম হতে হবে।',
        'numeric' => ':attribute অবশ্যই :value-এর কম হতে হবে।',
        'string' => ':attribute অবশ্যই :value অক্ষরের কম হতে হবে।',
    ],
    'lte' => [
        'array' => ':attribute-এ :value টির বেশি আইটেম থাকতে পারবে না।',
        'file' => ':attribute-এর আকার :value কিলোবাইটের বেশি হতে পারবে না।',
        'numeric' => ':attribute অবশ্যই :value অথবা তার কম হতে হবে।',
        'string' => ':attribute :value অক্ষরের বেশি হতে পারবে না।',
    ],
    'max' => [
        'array' => ':attribute-এ :max টির বেশি আইটেম থাকতে পারবে না।',
        'file' => ':attribute-এর আকার :max কিলোবাইটের বেশি হতে পারবে না।',
        'numeric' => ':attribute :max-এর বেশি হতে পারবে না।',
        'string' => ':attribute :max অক্ষরের বেশি হতে পারবে না।',
    ],
    'max_digits' => ':attribute-এ :max অঙ্কের বেশি থাকতে পারবে না।',
    'mimes' => ':attribute অবশ্যই এই ধরনের ফাইল হতে হবে: :values।',
    'mimetypes' => ':attribute অবশ্যই এই ধরনের ফাইল হতে হবে: :values।',
    'min' => [
        'array' => ':attribute-এ অন্তত :min টি আইটেম থাকতে হবে।',
        'file' => ':attribute-এর আকার অন্তত :min কিলোবাইট হতে হবে।',
        'numeric' => ':attribute অন্তত :min হতে হবে।',
        'string' => ':attribute অন্তত :min অক্ষরের হতে হবে।',
    ],
    'min_digits' => ':attribute-এ অন্তত :min অঙ্ক থাকতে হবে।',
    'missing' => ':attribute দেওয়া যাবে না।',
    'not_in' => 'নির্বাচিত :attribute সঠিক নয়।',
    'not_regex' => ':attribute-এর ফরম্যাট সঠিক নয়।',
    'numeric' => ':attribute অবশ্যই একটি সংখ্যা হতে হবে।',
    'password' => [
        'letters' => ':attribute-এ অন্তত একটি অক্ষর থাকতে হবে।',
        'mixed' => ':attribute-এ অন্তত একটি বড় হাতের ও একটি ছোট হাতের অক্ষর থাকতে হবে।',
        'numbers' => ':attribute-এ অন্তত একটি সংখ্যা থাকতে হবে।',
        'symbols' => ':attribute-এ অন্তত একটি চিহ্ন থাকতে হবে।',
        'uncompromised' => 'এই :attribute একটি ডেটা ফাঁসে পাওয়া গেছে। অনুগ্রহ করে অন্য একটি :attribute বেছে নিন।',
    ],
    'present' => ':attribute থাকতে হবে।',
    'prohibited' => ':attribute দেওয়া যাবে না।',
    'regex' => ':attribute-এর ফরম্যাট সঠিক নয়।',
    'required' => ':attribute দিতে হবে।',
    'required_if' => ':other :value হলে :attribute দিতে হবে।',
    'required_unless' => ':other :values না হলে :attribute দিতে হবে।',
    'required_with' => ':values দেওয়া থাকলে :attribute দিতে হবে।',
    'required_with_all' => ':values দেওয়া থাকলে :attribute দিতে হবে।',
    'required_without' => ':values দেওয়া না থাকলে :attribute দিতে হবে।',
    'required_without_all' => ':values-এর কোনোটিই দেওয়া না থাকলে :attribute দিতে হবে।',
    'same' => ':attribute এবং :other অবশ্যই একই হতে হবে।',
    'size' => [
        'array' => ':attribute-এ :size টি আইটেম থাকতে হবে।',
        'file' => ':attribute-এর আকার :size কিলোবাইট হতে হবে।',
        'numeric' => ':attribute অবশ্যই :size হতে হবে।',
        'string' => ':attribute অবশ্যই :size অক্ষরের হতে হবে।',
    ],
    'starts_with' => ':attribute অবশ্যই এগুলোর কোনো একটি দিয়ে শুরু হতে হবে: :values।',
    'string' => ':attribute অবশ্যই লেখা (টেক্সট) হতে হবে।',
    'timezone' => ':attribute একটি সঠিক টাইমজোন হতে হবে।',
    'unique' => 'এই :attribute আগেই ব্যবহার করা হয়েছে।',
    'uploaded' => ':attribute আপলোড করা যায়নি।',
    'url' => ':attribute একটি সঠিক URL হতে হবে।',
    'ulid' => ':attribute একটি সঠিক ULID হতে হবে।',
    'uuid' => ':attribute একটি সঠিক UUID হতে হবে।',

    'custom' => [],

    /*
    | Field names as customers and staff read them. Keep in step with the
    | English list in lang/en/validation.php.
    */
    'attributes' => [
        'name' => 'নাম',
        'full_name' => 'পুরো নাম',
        'phone' => 'ফোন নম্বর',
        'alternate_phone' => 'বিকল্প ফোন নম্বর',
        'email' => 'ইমেইল',
        'password' => 'পাসওয়ার্ড',
        'password_confirmation' => 'পাসওয়ার্ড নিশ্চিতকরণ',
        'otp' => 'ওটিপি কোড',
        'address' => 'ঠিকানা',
        'address_line' => 'ঠিকানা',
        'division' => 'বিভাগ',
        'division_id' => 'বিভাগ',
        'district' => 'জেলা',
        'district_id' => 'জেলা',
        'upazila' => 'উপজেলা',
        'upazila_id' => 'উপজেলা',
        'area' => 'এলাকা',
        'landmark' => 'কাছাকাছি পরিচিত স্থান',
        'note' => 'নোট',
        'quantity' => 'পরিমাণ',
        'price' => 'দাম',
        'sku' => 'SKU',
        'order_number' => 'অর্ডার নম্বর',
        'coupon_code' => 'কুপন কোড',
        'payment_method' => 'পেমেন্ট পদ্ধতি',
        'image' => 'ছবি',
        'date' => 'তারিখ',
        'locale' => 'ভাষা',
    ],

];
