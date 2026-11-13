import type { Locale } from '../i18n/config';

// Product reviews copy (P4-CAT-12), English and Bangla. Kept beside the review code rather than in
// lib/i18n/en.ts|bn.ts so it does not churn the shared catalogs. Western numerals in both
// (rule 36); tests/reviews.test.ts asserts both locales have the same keys.
// REVIEW(bn): first-draft Bangla, needs a native reader before launch.

const en = {
    tab: 'Reviews',
    title: 'Reviews',
    fromBuyers: 'from {count} reviews',
    reviewsCount: '{count} reviews',
    readMore: 'Read more',
    noneTitle: 'No reviews yet',
    noneBody: 'Be the first — we ask by SMS three days after delivery.',
    starsRow: '{stars} stars',
    histogramAria: '{stars} stars: {count} reviews',
    ratingAria: 'Rated {rating} out of 5',
    verified: 'Verified purchase',
    shopReply: 'Toy Mart BD',
    more: 'More reviews',
    write: 'Write a review',
    signInHint: 'You will be asked to sign in with your phone number.',
    formRating: 'Your rating',
    formStar: '{count} out of 5',
    formTitle: 'Title (optional)',
    formBody: 'Your review',
    formBodyHint: 'What did your child like? How was the quality and delivery?',
    formName: 'Name shown (optional)',
    formNameHint: 'Leave empty to show your first name and initial.',
    submit: 'Send review',
    sent: 'Thank you! Your review will appear after we check it.',
    duplicate: 'You have already reviewed this product.',
    invalid: 'Please choose a rating and write at least 10 characters.',
    failed: 'Your review could not be sent. Please try again.',
} as const;

type Copy = { -readonly [K in keyof typeof en]: string };

const bn: Copy = {
    tab: 'রিভিউ',
    title: 'রিভিউ',
    fromBuyers: '{count}টি রিভিউ থেকে',
    reviewsCount: '{count}টি রিভিউ',
    readMore: 'আরও পড়ুন',
    noneTitle: 'এখনো কোনো রিভিউ নেই',
    noneBody: 'কিনে প্রথম রিভিউ লিখুন — আমরা ডেলিভারির 3 দিন পর SMS এ জিজ্ঞেস করব',
    starsRow: '{stars} তারা',
    histogramAria: '{stars} তারা: {count}টি রিভিউ',
    ratingAria: '5-এর মধ্যে {rating} রেটিং',
    verified: 'ভেরিফাইড ক্রেতা',
    shopReply: 'টয় মার্ট বিডি',
    more: 'আরও রিভিউ',
    write: 'রিভিউ লিখুন',
    signInHint: 'আপনার ফোন নম্বর দিয়ে সাইন ইন করতে বলা হবে।',
    formRating: 'আপনার রেটিং',
    formStar: '5-এর মধ্যে {count}',
    formTitle: 'শিরোনাম (ঐচ্ছিক)',
    formBody: 'আপনার রিভিউ',
    formBodyHint: 'আপনার সন্তানের কী ভালো লেগেছে? মান ও ডেলিভারি কেমন ছিল?',
    formName: 'যে নাম দেখানো হবে (ঐচ্ছিক)',
    formNameHint: 'খালি রাখলে আপনার নামের প্রথম অংশ ও আদ্যক্ষর দেখানো হবে।',
    submit: 'রিভিউ পাঠান',
    sent: 'ধন্যবাদ! যাচাইয়ের পর আপনার রিভিউ দেখানো হবে।',
    duplicate: 'আপনি এই পণ্যের রিভিউ আগেই দিয়েছেন।',
    invalid: 'একটি রেটিং বাছুন এবং অন্তত 10 অক্ষর লিখুন।',
    failed: 'রিভিউ পাঠানো যায়নি। আবার চেষ্টা করুন।',
};

export type ReviewMessages = Copy;

export const reviewMessagesByLocale: Record<Locale, ReviewMessages> = { en, bn };

export function reviewMessages(locale: Locale): ReviewMessages {
    return reviewMessagesByLocale[locale];
}
