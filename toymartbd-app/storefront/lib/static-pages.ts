import type { Locale } from './i18n/config';

// Static page content, per locale. This is CONTENT, not UI chrome: it moves to the CMS (Phase 7)
// and the template (components/StaticPage.tsx) stays. Until then each page is a typed entry here.
//
// PLACEHOLDER: the About copy below is the Figma About page's copy (170:10502), which the design
// note says uses only claims already in the specs (05-content §A.12, §A.13). The shop address
// and visiting hours are placeholders (owner to confirm). It is marked `draft` and shows a visible notice until the
// owner signs it off. Bangla is a first draft awaiting native review like lib/i18n/bn.ts.

export interface StaticPageSection {
    heading: string;
    body: string[];
}

/** The About page's own blocks (Figma About us 170:10502 / 174:8244). */
export interface AboutBlocks {
    eyebrow: string;
    /** Hero paragraphs; the first is set in text/primary, the rest in text/secondary. */
    story: string[];
    agesBadge: [string, string];
    promises: Array<{ key: 'age' | 'returns' | 'cod' | 'nationwide'; title: string; body: string }>;
    valuesTitle: string;
    values: Array<{ title: string; body: string }>;
    visit: { title: string; body: string; directions: string; contact: string };
}

export interface StaticPageContent {
    title: string;
    about?: AboutBlocks;
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
            tone: 'sunrise',
            draft: true,
            metaDescription: 'Who we are, and what every Toy Mart BD order comes with.',
            sections: [],
            about: {
                eyebrow: 'Our story',
                story: [
                    'We started Toy Mart BD in Dhanmondi because buying a good toy in Bangladesh was harder than it should be: unclear age ranges, copies sold as originals, and no way to send anything back.',
                    'So we built the shop we wanted as parents: every toy checked for age and safety, honest prices, and a 7-day return if something is not right.',
                ],
                agesBadge: ['Ages', '0–12'],
                promises: [
                    { key: 'age', title: 'Age-checked toys', body: 'Every toy is labelled with a real age range and choking-hazard warnings.' },
                    { key: 'returns', title: '7-day returns', body: 'Wrong, broken or missing a part? Tell us within 7 days and we take it back.' },
                    { key: 'cod', title: 'Cash on delivery', body: 'Pay when the parcel is in your hands, anywhere we deliver.' },
                    { key: 'nationwide', title: 'Delivered nationwide', body: 'Toys reach Dhaka addresses in 24 hours, and we deliver across Bangladesh.' },
                ],
                valuesTitle: 'What we believe',
                values: [
                    { title: 'Play is how children learn', body: 'We pick toys that build hands, words and imagination, not just toys that flash.' },
                    { title: 'Parents deserve the truth', body: 'Real photos, real age ranges and prices that do not change at checkout.' },
                    { title: 'A real shop, real people', body: 'Visit us in Dhanmondi, or WhatsApp a person who knows the toys.' },
                ],
                visit: {
                    title: 'Come and play at our shop',
                    body: 'House 12, Road 7, Dhanmondi, Dhaka · Saturday to Thursday, 10 am – 8 pm',
                    directions: 'Get directions',
                    contact: 'Contact us',
                },
            },
        },
        bn: {
            title: 'টয় মার্ট বিডি সম্পর্কে',
            tone: 'sunrise',
            draft: true,
            metaDescription: 'আমরা কারা, আর টয় মার্ট বিডির প্রতিটি অর্ডারের সাথে কী থাকে।',
            sections: [],
            about: {
                eyebrow: 'আমাদের গল্প',
                story: [
                    'ধানমন্ডিতে টয় মার্ট বিডি শুরু করেছিলাম, কারণ বাংলাদেশে একটা ভালো খেলনা কেনা যতটা সহজ হওয়ার কথা, ততটা ছিল না: বয়সসীমা অস্পষ্ট, নকল জিনিস আসল বলে বিক্রি, আর ফেরত দেওয়ার কোনো উপায় নেই।',
                    'তাই বাবা-মা হিসেবে যে দোকানটা চেয়েছিলাম, সেটাই বানিয়েছি: প্রতিটি খেলনা বয়স ও নিরাপত্তার জন্য যাচাই করা, সৎ দাম, আর কিছু ঠিক না থাকলে 7 দিনে ফেরত।',
                ],
                agesBadge: ['বয়স', '0–12'],
                promises: [
                    { key: 'age', title: 'বয়স যাচাই করা খেলনা', body: 'প্রতিটি খেলনায় আসল বয়সসীমা আর গলায় আটকানোর ঝুঁকির সতর্কতা লেখা থাকে।' },
                    { key: 'returns', title: '7 দিনে ফেরত', body: 'ভুল, ভাঙা বা কোনো অংশ নেই? 7 দিনের মধ্যে জানান, আমরা ফেরত নেব।' },
                    { key: 'cod', title: 'ক্যাশ অন ডেলিভারি', body: 'পার্সেল হাতে পেয়ে টাকা দিন, যেখানেই আমরা ডেলিভারি দিই।' },
                    { key: 'nationwide', title: 'সারা দেশে ডেলিভারি', body: 'ঢাকার ঠিকানায় 24 ঘণ্টায় খেলনা পৌঁছায়, আর সারা বাংলাদেশে ডেলিভারি দিই।' },
                ],
                valuesTitle: 'আমরা যা বিশ্বাস করি',
                values: [
                    { title: 'খেলাই শিশুর শেখার পথ', body: 'আমরা এমন খেলনা বাছি যা হাত, ভাষা আর কল্পনা গড়ে — শুধু ঝলমলে খেলনা নয়।' },
                    { title: 'বাবা-মায়ের সত্যিটা জানার অধিকার আছে', body: 'আসল ছবি, আসল বয়সসীমা, আর চেকআউটে না বদলানো দাম।' },
                    { title: 'সত্যিকারের দোকান, সত্যিকারের মানুষ', body: 'ধানমন্ডিতে চলে আসুন, অথবা খেলনা চেনেন এমন একজন মানুষকে হোয়াটসঅ্যাপ করুন।' },
                ],
                visit: {
                    title: 'আমাদের দোকানে খেলতে আসুন',
                    body: 'বাসা 12, রোড 7, ধানমন্ডি, ঢাকা · শনি থেকে বৃহস্পতি, সকাল 10টা – রাত 8টা',
                    directions: 'পথ দেখুন',
                    contact: 'যোগাযোগ করুন',
                },
            },
        },
    },
} satisfies Record<string, Record<Locale, StaticPageContent>>;

export type StaticPageSlug = keyof typeof staticPages;

export function staticPage(slug: StaticPageSlug, locale: Locale): StaticPageContent {
    return staticPages[slug][locale];
}
