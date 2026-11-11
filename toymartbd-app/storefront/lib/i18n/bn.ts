// Bangla UI strings.
// REVIEW(bn): every string in this file is a first draft and MUST be checked by a native Bangla
// reader before launch (tone, conjunct rendering at 15px, length in buttons). Remove an entry's
// key from `pendingNativeReview` below once it has been signed off.
import type { Messages } from './en';

export const bn: Messages = {
    site: {
        name: 'টয় মার্ট বিডি',
        tagline: 'সব বয়সের খেলনা, সারা বাংলাদেশে ডেলিভারি।',
    },
    nav: {
        skipToContent: 'মূল অংশে যান',
        primary: 'প্রধান',
        home: 'হোম',
        brands: 'ব্র্যান্ড',
    },
    language: {
        switchLabel: 'ভাষা বদলান',
        en: 'English',
        bn: 'বাংলা',
    },
    suggest: {
        message: 'এই সাইটটি বাংলাতেও দেখা যায়।',
        accept: 'বাংলায় দেখুন',
        dismiss: 'ইংরেজিতেই থাকুন',
        region: 'ভাষার পরামর্শ',
    },
    home: {
        title: 'টয় মার্ট বিডি',
        intro: 'সব বয়সের খেলনা, সারা বাংলাদেশে ডেলিভারি।',
        browseBrands: 'ব্র্যান্ড দেখুন',
    },
    brands: {
        title: 'ব্র্যান্ড',
        metaDescription: 'টয় মার্ট বিডিতে ব্র্যান্ড অনুযায়ী খেলনা কিনুন।',
        empty: 'এখনো কোনো ব্র্যান্ড নেই।',
        backToAll: 'সব ব্র্যান্ড',
        logoAlt: '{name} লোগো',
    },
    notFound: {
        title: 'পাতাটি পাওয়া যায়নি',
        body: 'আপনি যে পাতাটি খুঁজছেন সেটি আমরা খুঁজে পাইনি।',
        back: 'হোম পেজে যান',
    },
    footer: {
        copyright: '© টয় মার্ট বিডি',
    },
};

/** Top-level groups whose Bangla is still awaiting native review. Empty this list to sign off. */
export const pendingNativeReview: ReadonlyArray<keyof Messages> = [
    'site',
    'nav',
    'language',
    'suggest',
    'home',
    'brands',
    'notFound',
    'footer',
];
