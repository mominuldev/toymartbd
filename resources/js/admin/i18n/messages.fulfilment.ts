/**
 * Manual fulfilment strings (P5, until Phase 6 courier booking): "Mark shipped" with an
 * optional courier name / tracking reference, "Mark delivered", and their bulk variants
 * on the order board. Spread into `en` / `bn` in messages.ts.
 *
 * NEEDS NATIVE-SPEAKER REVIEW, like the rest of the Bangla UI copy. Digits stay Western (rule 36).
 */
export const fulfilmentEn = {
    'orders.ship.action': 'Mark shipped',
    'orders.ship.title': 'Mark {number} shipped',
    'orders.ship.body': 'The parcel has left the shop — with a courier booked outside the system, or your own rider. Its stock is taken off the shelf now.',
    'orders.ship.courier': 'Courier',
    'orders.ship.courierHint': 'For example Pathao, Steadfast or own rider',
    'orders.ship.tracking': 'Tracking reference',
    'orders.ship.trackingHint': 'The consignment or tracking number on the courier receipt',
    'orders.ship.note': 'Note',
    'orders.ship.submit': 'Mark shipped',
    'orders.ship.done': '{number} marked shipped.',
    'orders.deliver.action': 'Mark delivered',
    'orders.deliver.title': 'Mark {number} delivered',
    'orders.deliver.body': 'The customer has the parcel. Revenue is recognised now; a cash-on-delivery payment is marked collected by the courier — the cash counts only when the courier pays it to you.',
    'orders.deliver.submit': 'Mark delivered',
    'orders.deliver.done': '{number} marked delivered.',
    'orders.bulk.ship': 'Mark shipped',
    'orders.bulk.shipping': 'Marking {count} shipped…',
    'orders.bulk.shippedAll': '{count} orders marked shipped.',
    'orders.bulk.deliver': 'Mark delivered',
    'orders.bulk.delivering': 'Marking {count} delivered…',
    'orders.bulk.deliveredAll': '{count} orders marked delivered.',
} as const;

export const fulfilmentBn: Record<keyof typeof fulfilmentEn, string> = {
    'orders.ship.action': 'পাঠানো হয়েছে চিহ্নিত করুন',
    'orders.ship.title': '{number} পাঠানো হয়েছে চিহ্নিত করুন',
    'orders.ship.body': 'পার্সেল দোকান থেকে বেরিয়ে গেছে — সিস্টেমের বাইরে বুক করা কুরিয়ারে, বা নিজস্ব রাইডারের হাতে। এর স্টক এখনই তাক থেকে কমানো হবে।',
    'orders.ship.courier': 'কুরিয়ার',
    'orders.ship.courierHint': 'যেমন Pathao, Steadfast বা নিজস্ব রাইডার',
    'orders.ship.tracking': 'ট্র্যাকিং রেফারেন্স',
    'orders.ship.trackingHint': 'কুরিয়ার রসিদের কনসাইনমেন্ট বা ট্র্যাকিং নম্বর',
    'orders.ship.note': 'নোট',
    'orders.ship.submit': 'পাঠানো হয়েছে চিহ্নিত করুন',
    'orders.ship.done': '{number} পাঠানো হয়েছে চিহ্নিত হয়েছে।',
    'orders.deliver.action': 'ডেলিভারড চিহ্নিত করুন',
    'orders.deliver.title': '{number} ডেলিভারড চিহ্নিত করুন',
    'orders.deliver.body': 'গ্রাহক পার্সেল পেয়েছেন। আয় এখনই স্বীকৃত হবে; ক্যাশ অন ডেলিভারি পেমেন্ট কুরিয়ার সংগ্রহ করেছে বলে চিহ্নিত হবে — নগদ গণ্য হবে কেবল কুরিয়ার আপনাকে টাকা পাঠালে।',
    'orders.deliver.submit': 'ডেলিভারড চিহ্নিত করুন',
    'orders.deliver.done': '{number} ডেলিভারড চিহ্নিত হয়েছে।',
    'orders.bulk.ship': 'পাঠানো হয়েছে চিহ্নিত করুন',
    'orders.bulk.shipping': '{count}টি পাঠানো হয়েছে চিহ্নিত হচ্ছে…',
    'orders.bulk.shippedAll': '{count}টি অর্ডার পাঠানো হয়েছে চিহ্নিত হয়েছে।',
    'orders.bulk.deliver': 'ডেলিভারড চিহ্নিত করুন',
    'orders.bulk.delivering': '{count}টি ডেলিভারড চিহ্নিত হচ্ছে…',
    'orders.bulk.deliveredAll': '{count}টি অর্ডার ডেলিভারড চিহ্নিত হয়েছে।',
};
