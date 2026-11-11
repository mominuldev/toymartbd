// English UI strings. Every user-facing string in a component comes from here or bn.ts (rule 35).
export const en = {
    site: {
        name: 'Toy Mart BD',
        tagline: 'Toys for every age, delivered across Bangladesh.',
    },
    nav: {
        skipToContent: 'Skip to content',
        primary: 'Main',
        home: 'Home',
        brands: 'Brands',
    },
    language: {
        switchLabel: 'Change language',
        en: 'English',
        bn: 'বাংলা',
    },
    suggest: {
        message: 'This site is also available in Bangla.',
        accept: 'View in Bangla',
        dismiss: 'Stay in English',
        region: 'Language suggestion',
    },
    home: {
        title: 'Toy Mart BD',
        intro: 'Toys for every age, delivered across Bangladesh.',
        browseBrands: 'Browse brands',
    },
    brands: {
        title: 'Brands',
        metaDescription: 'Shop toys by brand at Toy Mart BD.',
        empty: 'No brands to show yet.',
        backToAll: 'All brands',
        logoAlt: '{name} logo',
    },
    notFound: {
        title: 'Page not found',
        body: 'We could not find the page you were looking for.',
        back: 'Go to the home page',
    },
    footer: {
        copyright: '© Toy Mart BD',
    },
} as const;

type Widen<T> = { -readonly [K in keyof T]: T[K] extends string ? string : Widen<T[K]> };
export type Messages = Widen<typeof en>;
