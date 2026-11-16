/**
 * Store settings — Checkout & delivery rules (Figma B · 38), spread into `en` / `bn` in messages.ts. Own file so parallel work on other screens
 * does not collide.
 *
 * NEEDS NATIVE-SPEAKER REVIEW, like the rest of the Bangla UI copy. Digits stay Western.
 */
export const checkoutSettingsEn = {
    'checkoutSettings.title': 'Checkout & delivery rules',
    'checkoutSettings.saved': 'Checkout & delivery rules saved',
    'checkoutSettings.alwaysOn': 'Always on',
    'checkoutSettings.notAvailable': 'Not available yet',
    'checkoutSettings.guest.label': 'Guest checkout (no account needed)',
    'checkoutSettings.guest.caption': 'Customers check out with name and phone. An account is optional, so this cannot be switched off.',
    'checkoutSettings.cod.label': 'Cash on delivery available everywhere',
    'checkoutSettings.cod.noOnline': 'Every area we deliver to. No online payment is connected yet, so COD stays on.',
    'checkoutSettings.cod.online': 'Every area we deliver to. Online payment also accepted: {methods}.',
    'checkoutSettings.cod.advance': 'COD over {threshold} in {zones} is held until a {amount} advance is arranged by phone.',
    'checkoutSettings.advance.label': 'Require advance from high-risk orders (via bKash)',
    'checkoutSettings.advance.noGateway':
        'No bKash gateway is connected, so checkout cannot take an advance. Orders scoring {score} or more are held for verification instead, never cancelled.',
    'checkoutSettings.advance.noFlow':
        'Checkout cannot take a part payment yet. Orders scoring {score} or more are held for verification instead, never cancelled.',
    'checkoutSettings.free.label': 'Free delivery on orders of {amount} or more',
    'checkoutSettings.free.labelOff': 'Free delivery above a set order value',
    'checkoutSettings.free.caption': 'Applies in every zone, not only inside Dhaka. Same setting as in Courier settings.',
    'checkoutSettings.free.amount': 'Free delivery from (৳)',
    'checkoutSettings.free.invalid': 'Enter an amount above 0, like 3000 or 2999.50.',
    'checkoutSettings.notes.label': 'Allow order notes',
    'checkoutSettings.notes.caption': 'Customers can add a note of up to {max} characters; staff see it on the order.',
    'checkoutSettings.zone.inside_dhaka_metro': 'Inside Dhaka',
    'checkoutSettings.zone.dhaka_suburb': 'Dhaka suburbs',
    'checkoutSettings.zone.outside_dhaka': 'Outside Dhaka',
    'checkoutSettings.method.sslcommerz': 'SSLCommerz',
    'checkoutSettings.method.bkash': 'bKash',
    'checkoutSettings.method.nagad': 'Nagad',
    'checkoutSettings.method.card': 'Card',
    'checkoutSettings.charges.caption':
        'Customer-facing delivery charges (placeholders to confirm), plus a per-kg step for heavy parcels. Today they come from the default courier’s rate card ({courier}).',
    'checkoutSettings.charges.noCourier': 'No courier is active, so checkout cannot quote a delivery charge.',
    'checkoutSettings.charges.none': 'Not set',
    'checkoutSettings.charges.edit': 'Edit in Courier settings',
} as const;

export const checkoutSettingsBn: Record<keyof typeof checkoutSettingsEn, string> = {
    'checkoutSettings.title': 'চেকআউট ও ডেলিভারির নিয়ম',
    'checkoutSettings.saved': 'চেকআউট ও ডেলিভারির নিয়ম সংরক্ষিত হয়েছে',
    'checkoutSettings.alwaysOn': 'সবসময় চালু',
    'checkoutSettings.notAvailable': 'এখনও চালু হয়নি',
    'checkoutSettings.guest.label': 'অতিথি চেকআউট (অ্যাকাউন্ট লাগবে না)',
    'checkoutSettings.guest.caption': 'গ্রাহক নাম ও ফোন দিয়ে অর্ডার করেন। অ্যাকাউন্ট ঐচ্ছিক, তাই এটি বন্ধ করা যায় না।',
    'checkoutSettings.cod.label': 'সব জায়গায় ক্যাশ অন ডেলিভারি',
    'checkoutSettings.cod.noOnline': 'আমরা যেখানে ডেলিভারি দিই সব জায়গায়। কোনো অনলাইন পেমেন্ট এখনও যুক্ত নয়, তাই COD চালু থাকে।',
    'checkoutSettings.cod.online': 'আমরা যেখানে ডেলিভারি দিই সব জায়গায়। অনলাইন পেমেন্টও নেওয়া হয়: {methods}।',
    'checkoutSettings.cod.advance': '{zones}-এ {threshold}-এর বেশি COD অর্ডার ফোনে {amount} অগ্রিম ঠিক না হওয়া পর্যন্ত আটকে থাকে।',
    'checkoutSettings.advance.label': 'উচ্চ ঝুঁকির অর্ডারে অগ্রিম নিন (বিকাশে)',
    'checkoutSettings.advance.noGateway':
        'কোনো বিকাশ গেটওয়ে যুক্ত নয়, তাই চেকআউটে অগ্রিম নেওয়া যায় না। {score} বা বেশি স্কোরের অর্ডার যাচাইয়ের জন্য আটকে রাখা হয়, কখনও বাতিল হয় না।',
    'checkoutSettings.advance.noFlow':
        'চেকআউটে এখনও আংশিক পেমেন্ট নেওয়া যায় না। {score} বা বেশি স্কোরের অর্ডার যাচাইয়ের জন্য আটকে রাখা হয়, কখনও বাতিল হয় না।',
    'checkoutSettings.free.label': '{amount} বা তার বেশি অর্ডারে ফ্রি ডেলিভারি',
    'checkoutSettings.free.labelOff': 'নির্দিষ্ট অঙ্কের বেশি অর্ডারে ফ্রি ডেলিভারি',
    'checkoutSettings.free.caption': 'শুধু ঢাকার ভেতরে নয়, সব জোনে প্রযোজ্য। কুরিয়ার সেটিংসের একই সেটিং।',
    'checkoutSettings.free.amount': 'ফ্রি ডেলিভারি শুরু (৳)',
    'checkoutSettings.free.invalid': '0-এর বেশি একটি অঙ্ক দিন, যেমন 3000 বা 2999.50।',
    'checkoutSettings.notes.label': 'অর্ডার নোট রাখতে দিন',
    'checkoutSettings.notes.caption': 'গ্রাহক সর্বোচ্চ {max} অক্ষরের নোট দিতে পারেন; স্টাফ অর্ডারে তা দেখেন।',
    'checkoutSettings.zone.inside_dhaka_metro': 'ঢাকার ভেতরে',
    'checkoutSettings.zone.dhaka_suburb': 'ঢাকার উপশহর',
    'checkoutSettings.zone.outside_dhaka': 'ঢাকার বাইরে',
    'checkoutSettings.method.sslcommerz': 'SSLCommerz',
    'checkoutSettings.method.bkash': 'বিকাশ',
    'checkoutSettings.method.nagad': 'নগদ',
    'checkoutSettings.method.card': 'কার্ড',
    'checkoutSettings.charges.caption':
        'গ্রাহকের ডেলিভারি চার্জ (নিশ্চিত হওয়া বাকি), ভারী পার্সেলে প্রতি কেজির অতিরিক্ত চার্জসহ। এখন এগুলো ডিফল্ট কুরিয়ারের রেট কার্ড ({courier}) থেকে আসে।',
    'checkoutSettings.charges.noCourier': 'কোনো কুরিয়ার চালু নেই, তাই চেকআউট ডেলিভারি চার্জ দিতে পারে না।',
    'checkoutSettings.charges.none': 'ঠিক করা নেই',
    'checkoutSettings.charges.edit': 'কুরিয়ার সেটিংসে বদলান',
};
