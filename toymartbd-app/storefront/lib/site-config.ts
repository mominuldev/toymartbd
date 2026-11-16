// Business contact details and footer navigation (storefront spec C2.6: one value per field,
// rendered everywhere from that field).
//
// The live values come from the API: Admin → Settings → Store → Business, served by
// `GET /storefront/store-info` and read server-side by lib/store-info.ts (`getStoreInfo()`,
// cache tag `store-info`, launch item F4). This file keeps only the SAMPLES that stand in for a
// value the owner has not set yet, plus the static navigation.
//
// SAMPLES: every value below is from the design files (UI-UX-DESIGN-SPEC §10, PRD §7 item 6),
// carries `placeholder: true`, is rendered with `data-placeholder="owner-to-confirm"`, and is
// never turned into a tel:/mailto:/wa.me link, so nobody can dial a made-up number from the live
// site. Note the designs disagree on the hotline (content spec 09600-123456, built footer
// 09610-123456); the footer's value is the sample.
//
// Client-safe: no secrets here. Components never import the samples directly for display: a
// Server Component calls `getStoreInfo()`, a client component gets the values as props, and only
// a client error boundary that cannot reach the API falls back to `sampleStoreInfo`.

export interface ContactValue {
    /** As shown, Western digits in both locales (rule 36). */
    display: string;
    /** Link target once confirmed (tel:, mailto:, https://wa.me/…). Never used while `placeholder`. */
    href: string;
    placeholder: boolean;
}

export interface StoreContact {
    hotline: ContactValue;
    whatsapp: ContactValue;
    email: ContactValue;
    /**
     * The Facebook Page behind `https://m.me/{page}`, or null while the owner has not set one. No
     * sample: a Messenger link is shown only once it is real (lib/chat-links.ts).
     */
    messenger: ContactValue | null;
}

export interface StoreLegal {
    /** Trade licence and BIN for the legal bar; null until the owner supplies them. */
    tradeLicence: string | null;
    bin: string | null;
}

/** An uploaded brand image (absolute URL on the media host, intrinsic size of that rendition). */
export interface BrandImage {
    url: string;
    width: number;
    height: number;
}

/** PNG favicons Laravel renders from the store icon (absolute URLs); each null until the job ran. */
export interface StoreIcons {
    png32: string | null;
    png180: string | null;
    png192: string | null;
    png512: string | null;
}

/**
 * Site & branding (Admin → Settings → Store → Site & branding). `siteName` is always set: the
 * owner's site name, else the business name, else the catalog name. Everything else is null while
 * unset, and the storefront keeps today's look (wordmark, catalog tagline, no share image).
 */
export interface StoreBranding {
    siteName: { en: string; bn: string };
    tagline: { en: string | null; bn: string | null };
    description: { en: string | null; bn: string | null };
    /** For light backgrounds: the header. */
    logo: BrandImage | null;
    /** For dark backgrounds. Unused today: no storefront surface is dark (the footer is `bg-surface`). */
    logoDark: BrandImage | null;
    /** 1200 × 630 Open Graph / WhatsApp card image. */
    shareImage: BrandImage | null;
    icons: StoreIcons | null;
}

/** What the storefront chrome shows about the business (lib/store-info.ts builds it from the API). */
export interface StoreInfo {
    name: { en: string; bn: string };
    contact: StoreContact;
    legal: StoreLegal;
    /**
     * Street address per locale, or null while the owner has not set it: a renderer then uses the
     * catalog copy `footer.contact.address` (also a sample). Nothing renders an address today.
     */
    address: { en: string | null; bn: string | null };
    branding: StoreBranding;
}

/** The design-file samples, each marked `placeholder: true`. */
export const sampleContact = {
    hotline: { display: '09610-123456', href: 'tel:+8809610123456', placeholder: true },
    whatsapp: { display: '01712-345678', href: 'https://wa.me/8801712345678', placeholder: true },
    email: { display: 'hello@toymartbd.com', href: 'mailto:hello@toymartbd.com', placeholder: true },
    messenger: null,
} as const satisfies StoreContact;

/** Trade licence and BIN have no sample: the legal bar shows `footer.legalPending` instead. */
export const pendingLegal: StoreLegal = { tradeLicence: null, bin: null };

/** Everything unset: the fallback when the API cannot be reached, and for client error boundaries. */
const sampleName = { en: 'Toy Mart BD', bn: 'টয় মার্ট বিডি' } as const;

/** No branding set: the catalog name, and nothing else (wordmark, catalog tagline, no images). */
export const unsetBranding: StoreBranding = {
    siteName: sampleName,
    tagline: { en: null, bn: null },
    description: { en: null, bn: null },
    logo: null,
    logoDark: null,
    shareImage: null,
    icons: null,
};

export const sampleStoreInfo: StoreInfo = {
    name: sampleName,
    contact: sampleContact,
    legal: pendingLegal,
    address: { en: null, bn: null },
    branding: unsetBranding,
};

/**
 * Where a WhatsApp button points: the confirmed wa.me link, or — while the number is a sample —
 * `fallback` (the contact page), so no visitor messages a made-up number.
 */
export function whatsappHref(whatsapp: ContactValue, fallback: string): string {
    return whatsapp.placeholder ? fallback : whatsapp.href;
}

/**
 * CMS pages the layout links to directly (consent banner, footer legal bar, contact fallbacks).
 * The slugs are the ones database/seeders/StaticPagesSeeder.php creates — a link to any other
 * slug is a sitewide 404 (Phase 9 SEO audit: `/privacy` never existed).
 */
export const cmsPaths = {
    privacy: '/privacy-policy',
    contact: '/contact',
} as const;

/**
 * Footer link tiles (Figma Footer 124:3310: Shop · Help · About) — the FALLBACK when the admin
 * has not built a footer menu (Content → Menus). `label` is a key of `footer.links` in the
 * message catalogs; `path` is locale-independent. Every path is a real route or a slug that
 * database/seeders/StaticPagesSeeder.php creates (tests/seo-audit.test.ts): the Phase 9 SEO
 * audit found ten of these pointing at routes that never existed (sitewide 404 links). Design
 * entries with no route yet (By age, Best sellers, Toy safety) are left out until they exist.
 */
export const footerColumns = [
    {
        title: 'shop',
        links: [
            { label: 'allToys', path: '/toys' },
            { label: 'byAge', path: '/#shop-by-age' },
            { label: 'flashSale', path: '/flash-sale' },
            { label: 'newArrivals', path: '/toys?sort=newest' },
            // No best-seller sort on listings; `featured` is what the home Best sellers rail ranks first.
            { label: 'bestSellers', path: '/toys?sort=featured' },
            { label: 'brands', path: '/brands' },
        ],
    },
    {
        title: 'help',
        links: [
            { label: 'track', path: '/track' },
            { label: 'delivery', path: '/delivery-charge' },
            { label: 'returns', path: '/returns-policy' },
            { label: 'faq', path: '/faq' },
            { label: 'contact', path: cmsPaths.contact },
        ],
    },
    {
        title: 'about',
        links: [
            { label: 'about', path: '/about' },
            { label: 'blog', path: '/blog' },
            { label: 'privacy', path: cmsPaths.privacy },
            { label: 'terms', path: '/terms' },
        ],
    },
] as const;

export type FooterColumn = (typeof footerColumns)[number];

/**
 * Social profiles for the footer "Say hi!" tile. PLACEHOLDERS: the owner has not supplied the
 * URLs (Figma Footer description), so `href` is null and the tiles render as non-link, labelled
 * images until it is.
 */
export const social: ReadonlyArray<{ key: 'facebook' | 'instagram' | 'youtube' | 'tiktok'; href: string | null }> = [
    { key: 'facebook', href: null },
    { key: 'instagram', href: null },
    { key: 'youtube', href: null },
    { key: 'tiktok', href: null },
];

/**
 * Organization structured data (lib/seo/jsonld.ts). PLACEHOLDERS: there is no final logo file
 * (the wordmark is a design placeholder, Figma 123:1712) and the registered legal name is not
 * confirmed. While `placeholder: true` the value is NOT emitted in JSON-LD. Replace and flip the
 * flag once the owner supplies them (UI-UX-DESIGN-SPEC §10).
 */
export const organization: {
    logo: { path: string; placeholder: boolean };
    legalName: { value: string; placeholder: boolean };
} = {
    logo: { path: '/images/logo-512.png', placeholder: true },
    legalName: { value: 'Toy Mart BD', placeholder: true },
};
