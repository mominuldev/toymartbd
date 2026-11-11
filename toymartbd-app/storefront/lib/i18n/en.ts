// English UI strings. Every user-facing string in a component comes from here or bn.ts (rule 35).
// Copy source: docs/_design/05-content-and-assets-spec.md (keys noted where they map 1:1).
// Phone numbers are never written into a string: they come from lib/site-config.ts via {phone}.
export const en = {
    site: {
        name: 'Toy Mart BD',
        tagline: 'Toys for every age, delivered across Bangladesh.',
        // The placeholder logotype (Figma Wordmark 123:1712). It stays Latin in both locales.
        wordmark: { first: 'Toy', second: 'Mart', tag: 'BD' },
    },
    announcement: {
        cod: 'Cash on delivery all over Bangladesh', // Figma AnnouncementBar 123:1729 (nav.announcement.cod)
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
        menu: 'Menu',
        search: 'Search',
        hotline: 'Hotline',
        languageShort: { en: 'EN', bn: 'বাং' },
    },
    tabs: {
        label: 'Shortcuts',
        home: 'Home', // nav.tab.home
        categories: 'Categories', // nav.tab.categories
        search: 'Search', // nav.tab.search
        cart: 'Cart', // nav.tab.cart
        account: 'Account', // Figma MobileNav 111:784
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
        placeholder: 'Search toys, brands or age', // Figma SearchBar 111:853
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
        eyebrow: 'Error 404 · Page not found',
        title: "This page isn't here",
        body: 'The link may be old, or there may be a typo. Nothing has happened to your cart or your orders.',
        home: 'Go to the home page',
        search: 'Search for a toy',
        browse: 'Browse all toys',
        categoriesTitle: 'Or start with a category',
        seeAll: 'See all',
    },
    serverError: {
        // 05-content §C.8
        eyebrow: 'Error 500',
        title: 'Something broke on our side',
        body: 'This is our fault, not yours. Try again in a minute, or call {phone} and we will place the order for you.', // Figma 169:10286
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
        address: 'House 12, Road 7, Dhanmondi, Dhaka', // PLACEHOLDER (owner to confirm)
        legal: '© {year} Toy Mart BD · {address}',
    },
    footer: {
        // Figma Footer 124:3310 (bento redesign, owner 2026-11-11) supersedes 05-content §A.12 here.
        copyright: '© {year} Toy Mart BD',
        helpTile: {
            status: 'Online now · 9 am – 9 pm', // PLACEHOLDER hours (owner to confirm)
            title: 'Stuck choosing a toy? We love helping.',
            body: 'Tell us the age and budget — we’ll suggest three picks.',
            whatsapp: 'Chat on WhatsApp',
        },
        follow: 'Say hi!',
        social: {
            facebook: 'Toy Mart BD on Facebook',
            instagram: 'Toy Mart BD on Instagram',
            youtube: 'Toy Mart BD on YouTube',
            tiktok: 'Toy Mart BD on TikTok',
        },
        aboutBody: 'A toy shop run by people who answer the phone. Based in Dhaka, delivering across Bangladesh.',
        columns: {
            shop: 'Shop',
            help: 'Help',
            about: 'About',
        },
        links: {
            allToys: 'All toys',
            byAge: 'Shop by age',
            flashSale: 'Flash sale',
            newArrivals: 'New arrivals',
            bestSellers: 'Best sellers',
            brands: 'Brands',
            track: 'Track your order',
            delivery: 'Delivery charges',
            returns: 'Returns & refunds',
            faq: 'FAQ',
            contact: 'Contact us',
            about: 'Our story',
            safety: 'Toy safety',
            blog: 'Blog',
            privacy: 'Privacy policy',
            terms: 'Terms',
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
        legalPending: 'Trade licence: to be confirmed',
        sample: 'sample',
        sampleNotice: 'Contact details marked "sample" are placeholders until the owner confirms them.',
    },
    pages: {
        draftNotice: 'Draft copy — awaiting review before launch.',
        browse: 'Browse all toys',
        home: 'Go to the home page',
    },
    listing: {
        // P4-STO-03 category and brand listings (storefront spec §A1, §A7, §A8; Figma 129:1572 / 136:2172).
        breadcrumb: 'Breadcrumb',
        home: 'Home',
        all: 'All',
        subcategories: 'Sub-categories',
        countOf: '{shown} of {total} products',
        products: '{count} products',
        showing: 'Showing {shown} of {total} products',
        showingAll: 'Showing all {total} products',
        loadMore: 'Show more products',
        filters: 'Filters',
        filtersWithCount: 'Filters ({count})',
        clearAll: 'Clear all',
        apply: 'Show results',
        close: 'Close filters',
        activeFilters: 'Active filters',
        removeFilter: 'Remove filter: {label}',
        zeroOption: 'No products match this with your other filters',
        showMore: 'Show {count} more',
        facetAge: 'Age',
        facetPrice: 'Price',
        facetBrand: 'Brand',
        facetCategory: 'Category',
        facetGender: 'Gender',
        chipAge: 'Age: {label}',
        chipBrand: 'Brand: {label}',
        chipCategory: 'Category: {label}',
        chipGender: '{label}',
        ageBands: {
            '0-12': '0–12 months',
            '12-24': '1–2 years',
            '24-48': '2–4 years',
            '48-72': '4–6 years',
            '72-96': '6–8 years',
            '96-144': '8–12 years',
            '144-': '12+ years',
        },
        priceUnder: 'Under {max}',
        priceRange: '{min}–{max}',
        priceOver: '{min}+',
        genders: { unisex: 'For everyone', boys: 'Boys', girls: 'Girls' },
        sortLabel: 'Sort: {value}',
        sortBy: 'Sort by',
        sorts: {
            featured: 'Featured',
            newest: 'Newest first',
            price_asc: 'Price: low to high',
            price_desc: 'Price: high to low',
            rating: 'Top rated',
            name: 'Name: A to Z',
        },
        ageMonths: '{min}–{max} months',
        ageMonthsPlus: '{min} months+',
        ageYears: '{min}–{max} years',
        ageYearsPlus: '{min}+ years',
        ageUpTo: 'Up to {max} years',
        from: 'from {price}',
        was: 'Was {price}',
        discount: '−{percent}%',
        buyNow: 'Buy now',
        addToCart: 'Add to cart: {name}',
        wishlist: 'Save {name} to wishlist',
        noImage: 'No photo yet',
        emptyTitle: 'No toys match these filters',
        emptyBody: 'Try removing a filter, or browse everything in this section.',
        emptyNoProducts: 'Toys for this section are on their way.',
        emptyClear: 'Clear all filters',
        emptyBrowse: 'Browse all categories',
        metaCategory: 'Shop {category} toys at Toy Mart BD — prices in taka, cash on delivery.',
        metaBrand: 'Shop {brand} toys at Toy Mart BD — prices in taka, cash on delivery.',
    },
} as const;

type Widen<T> = { -readonly [K in keyof T]: T[K] extends string ? string : Widen<T[K]> };
export type Messages = Widen<typeof en>;
