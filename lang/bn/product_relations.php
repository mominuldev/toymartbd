<?php

declare(strict_types=1);

/*
 * পণ্যের হাতে বাছাই করা সুপারিশের নিয়ম (ProductRelationsWriter, rule 25c)। সংখ্যা পশ্চিমা অঙ্কে (rule 36)।
 * NEEDS NATIVE-SPEAKER REVIEW.
 */
return [
    'self' => 'কোনো পণ্য নিজের পাতাতেই নিজেকে সুপারিশ করতে পারে না।',
    'duplicate' => 'এই পণ্যটি একই ধরনের সুপারিশে আগেই যোগ করা আছে।',
    'missing' => 'সুপারিশ করা পণ্যটি নেই।',
    'upsell_not_dearer' => 'আপসেলের দাম এই পণ্যের চেয়ে বেশি হতে হবে: :name-এর দাম ৳:target, এই পণ্যের দাম ৳:source।',
    'upsell_no_source_price' => 'আপসেল যোগ করার আগে এই পণ্যের একটি সক্রিয় দাম দিন।',
    'upsell_no_target_price' => ':name-এর কোনো সক্রিয় দাম নেই, তাই এটি আপসেল হতে পারে না।',
];
