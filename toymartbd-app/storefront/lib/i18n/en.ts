// English UI strings. Every user-facing string in a component comes from here or bn.ts (rule 35).
// Copy source: docs/_design/05-content-and-assets-spec.md (keys noted where they map 1:1).
// Phone numbers are never written into a string: they come from lib/site-config.ts via {phone}.
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
        logoAria: 'Toy Mart BD — home', // nav.logo.aria
        categoriesMenu: 'Shop by category',
        allCategories: 'All categories', // nav.link.all_categories
        more: 'More',
        viewAll: 'All in {category}', // nav.megamenu.view_all
        wishlist: 'Wishlist', // nav.link.wishlist
        account: 'Account',
        cartEmpty: 'Cart, empty', // nav.cart.empty.aria
        cartCount: 'Cart, {count} items', // nav.cart.aria
    },
    tabs: {
        label: 'Shortcuts',
        home: 'Home', // nav.tab.home
        categories: 'Categories', // nav.tab.categories
        search: 'Search', // nav.tab.search
        cart: 'Cart', // nav.tab.cart
        account: 'You', // nav.tab.account.short
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
    search: {
        label: 'Search', // search.aria.label
        placeholder: 'Search toys, brands, age', // search.placeholder
        submit: 'Search', // search.submit
        pageTitle: 'Search',
        resultsTitle: 'Results for "{query}"', // search.results.title
        // PLACEHOLDER copy until P4-STO-04 (search results) ships.
        comingSoon: 'Search results are on their way. Until then, browse by category.',
        emptyQuery: 'Type a toy, a brand or an age to search.',
        metaDescription: 'Search toys at Toy Mart BD.',
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
    categories: {
        title: 'All categories',
        metaDescription: 'Browse every toy category at Toy Mart BD.',
        empty: 'Categories will appear here soon.',
        inThisCategory: 'In this category',
        // PLACEHOLDER copy until P4-STO-03 (category listing) ships.
        comingSoon: 'The toys in this category are being added. Browse the sections below or search in the meantime.',
        metaCategory: 'Shop {category} at Toy Mart BD.',
    },
    notFound: {
        // 05-content §C.7
        title: "This page isn't here",
        body: 'The link may be old, or there may be a typo. Nothing has happened to your cart or your orders.',
        home: 'Go to the home page',
        search: 'Search for a toy',
        browse: 'Browse all toys',
    },
    serverError: {
        // 05-content §C.8
        title: 'Something broke on our side',
        body: 'This is our fault, not yours. No money was taken and your cart is safe. Try again in a minute — or call {phone} and we will place the order for you.',
        retry: 'Try again',
        call: 'Call {phone}',
        reassureMoney: 'No money was taken',
        reassureCart: 'Your cart is safe',
        reassureOrders: 'Orders already placed are not affected',
        reassureLabel: 'What has not changed',
    },
    maintenance: {
        // 05-content §A.14 page.maintenance.*
        title: 'Back in a few minutes',
        body: 'We are updating the site. Orders already placed are safe. Call {phone} if you need anything now.',
        call: 'Call {phone}',
        whatsapp: 'Chat on WhatsApp',
    },
    footer: {
        copyright: '© {year} Toy Mart BD. All rights reserved.', // footer.copyright
        aboutBody: 'A toy shop run by people who answer the phone. Based in Dhaka, delivering across Bangladesh.',
        columns: {
            shop: 'Shop',
            help: 'Help',
            company: 'Company',
        },
        links: {
            allToys: 'All toys',
            brands: 'Brands',
            newArrivals: 'New arrivals',
            offers: 'Offers',
            byAge: 'Shop by age',
            track: 'Track your order',
            delivery: 'Delivery charges',
            returns: 'How returns work',
            faq: 'Common questions',
            contact: 'Contact us',
            about: 'About us',
            privacy: 'Privacy policy',
            terms: 'Terms of use',
            safety: 'Toy safety',
        },
        contact: {
            title: 'Talk to us', // footer.contact.title
            hotline: 'Hotline',
            whatsapp: 'WhatsApp',
            email: 'Email',
            hours: '9 AM – 9 PM, Saturday to Thursday', // PLACEHOLDER (owner to confirm)
            address: 'House 12, Road 7, Dhanmondi, Dhaka 1205', // PLACEHOLDER (owner to confirm)
        },
        paymentTitle: 'Ways to pay',
        cod: 'Cash on delivery',
        tradeLicence: 'Trade licence {number} · BIN {bin}',
        legalPending: 'Trade licence and BIN: to be added',
        sample: 'sample',
        sampleNotice: 'Contact details marked "sample" are placeholders until the owner confirms them.',
    },
    pages: {
        draftNotice: 'Draft copy — awaiting review before launch.',
        browse: 'Browse all toys',
        home: 'Go to the home page',
    },
} as const;

type Widen<T> = { -readonly [K in keyof T]: T[K] extends string ? string : Widen<T[K]> };
export type Messages = Widen<typeof en>;
