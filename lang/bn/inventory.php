<?php

declare(strict_types=1);

/*
| ইনভেন্টরি — API-র বার্তা (স্টক সমন্বয়)। NEEDS NATIVE-SPEAKER REVIEW. Digits stay Western (rule 36).
*/
return [

    'adjust' => [
        'no_change' => 'এতে স্টকের কোনো পরিবর্তন হয় না।',
        'only_in' => 'এই কারণে শুধু স্টক যোগ করা যায়।',
        'only_out' => 'এই কারণে শুধু স্টক কমানো যায়।',
        'below_reserved' => 'খোলা অর্ডারের জন্য সংরক্ষিত :reserved ইউনিটের চেয়ে কম থাকবে (হাতে আছে :on_hand)।',
        'duplicate_variant' => 'একটি সমন্বয়ে প্রতিটি ভ্যারিয়েন্ট একবারই থাকতে পারে।',
        'quantity_or_counted' => 'পরিবর্তন অথবা গণনা করা পরিমাণ — যেকোনো একটি দিন, দুটো নয়।',
    ],

];
