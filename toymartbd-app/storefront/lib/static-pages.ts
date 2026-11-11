import type { Locale } from './i18n/config';

// Static page content, per locale. This is CONTENT, not UI chrome: it moves to the CMS (Phase 7)
// and the template (components/StaticPage.tsx) stays. Until then each page is a typed entry here.
//
// PLACEHOLDER: the About copy below is assembled only from claims already in the specs
// (05-content-and-assets-spec.md §A.12 footer.about.body and §A.13 trust strip), as the design
// note for the About page requires. It is marked `draft` and shows a visible notice until the
// owner signs it off. Bangla is a first draft awaiting native review like lib/i18n/bn.ts.

export interface StaticPageSection {
    heading: string;
    body: string[];
}

export interface StaticPageContent {
    title: string;
    lead?: string;
    tone: 'sunrise' | 'sky' | 'mint' | 'candy';
    sections: StaticPageSection[];
    draft: boolean;
    metaDescription: string;
}

export const staticPages = {
    about: {
        en: {
            title: 'About Toy Mart BD',
            lead: 'A toy shop run by people who answer the phone. Based in Dhaka, delivering across Bangladesh.',
            tone: 'sunrise',
            draft: true,
            metaDescription: 'Who we are, and what every Toy Mart BD order comes with.',
            sections: [
                {
                    heading: 'What every order comes with',
                    body: [
                        'Cash on delivery: pay when the parcel reaches you.',
                        '7-day return: if the box is unopened, we take it back.',
                        'Genuine toys: bought from importers, with papers.',
                        'Age-checked: every toy has a checked age range.',
                    ],
                },
                {
                    heading: 'We answer the phone',
                    body: ['If you are unsure which toy suits your child, call or message us. A person picks up — our hours and numbers are at the bottom of every page.'],
                },
            ],
        },
        bn: {
            title: 'টয় মার্ট বিডি সম্পর্কে',
            lead: 'এমন একটি খেলনার দোকান, যারা ফোন ধরে। ঢাকায় আমাদের অফিস, ডেলিভারি সারা দেশে।',
            tone: 'sunrise',
            draft: true,
            metaDescription: 'আমরা কারা, আর টয় মার্ট বিডির প্রতিটি অর্ডারের সাথে কী থাকে।',
            sections: [
                {
                    heading: 'প্রতিটি অর্ডারের সাথে যা পাবেন',
                    body: [
                        'ক্যাশ অন ডেলিভারি: পার্সেল হাতে পেয়ে টাকা দিন।',
                        '7 দিনের রিটার্ন: বাক্স না খুললে ফেরত নেব।',
                        'আসল খেলনা: আমদানিকারকের কাছ থেকে কেনা, কাগজসহ।',
                        'বয়স মিলিয়ে দেখা: প্রতিটি খেলনার বয়সসীমা যাচাই করা।',
                    ],
                },
                {
                    heading: 'ফোন আমরা ধরি',
                    body: ['বাচ্চার জন্য কোন খেলনা ঠিক হবে বুঝতে না পারলে ফোন বা মেসেজ করুন। একজন মানুষই উত্তর দেবেন — আমাদের সময় ও নম্বর প্রতিটি পাতার নিচে আছে।'],
                },
            ],
        },
    },
} satisfies Record<string, Record<Locale, StaticPageContent>>;

export type StaticPageSlug = keyof typeof staticPages;

export function staticPage(slug: StaticPageSlug, locale: Locale): StaticPageContent {
    return staticPages[slug][locale];
}
