// Business contact details and footer navigation — the ONE place the storefront reads them from
// (storefront spec C2.6: one value per field, rendered everywhere from that field).
//
// PLACEHOLDERS: the owner has not supplied the hotline, WhatsApp number, email, address,
// trade licence or BIN yet (UI-UX-DESIGN-SPEC §10, PRD §7 item 6). Every value below marked
// `placeholder: true` is a sample from the design files, is rendered with a visible
// "sample" marker, and is never turned into a tel:/mailto:/wa.me link, so nobody can dial a
// made-up number from the live site. Replace the value and set `placeholder: false` once the
// owner confirms it. Note the designs disagree on the hotline (content spec 09600-123456,
// built footer 09610-123456); the footer's value is used until the owner confirms.
//
// Client-safe: no secrets here.

export interface ContactValue {
    /** As shown, Western digits in both locales (rule 36). */
    display: string;
    /** Link target once confirmed (tel:, mailto:, https://wa.me/…). */
    href: string;
    placeholder: boolean;
}

export const contact = {
    hotline: { display: '09610-123456', href: 'tel:+8809610123456', placeholder: true },
    whatsapp: { display: '01712-345678', href: 'https://wa.me/8801712345678', placeholder: true },
    email: { display: 'hello@toymartbd.com', href: 'mailto:hello@toymartbd.com', placeholder: true },
} satisfies Record<string, ContactValue>;

/**
 * CMS pages the layout links to directly (consent banner, footer legal bar, contact fallbacks).
 * The slugs are the ones database/seeders/StaticPagesSeeder.php creates — a link to any other
 * slug is a sitewide 404 (Phase 9 SEO audit: `/pages/privacy` never existed).
 */
export const cmsPaths = {
    privacy: '/pages/privacy-policy',
    contact: '/pages/contact',
} as const;

/** Trade licence and BIN for the legal bar. `null` until the owner supplies them. */
export const legal: { tradeLicence: string | null; bin: string | null } = {
    tradeLicence: null,
    bin: null,
};

/** Street address and support hours are copy (they differ per locale) — see `footer.contact` in the catalogs, also placeholders. */
export const addressIsPlaceholder = true;

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
            { label: 'flashSale', path: '/flash-sale' },
            { label: 'newArrivals', path: '/toys?sort=newest' },
            { label: 'brands', path: '/brands' },
        ],
    },
    {
        title: 'help',
        links: [
            { label: 'track', path: '/track' },
            { label: 'delivery', path: '/delivery-charge' },
            { label: 'returns', path: '/pages/returns-policy' },
            { label: 'faq', path: '/pages/faq' },
            { label: 'contact', path: cmsPaths.contact },
        ],
    },
    {
        title: 'about',
        links: [
            { label: 'about', path: '/pages/about' },
            { label: 'blog', path: '/blog' },
            { label: 'privacy', path: cmsPaths.privacy },
            { label: 'terms', path: '/pages/terms' },
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
