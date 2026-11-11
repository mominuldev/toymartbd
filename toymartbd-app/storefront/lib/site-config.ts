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

/** Trade licence and BIN for the legal bar. `null` until the owner supplies them. */
export const legal: { tradeLicence: string | null; bin: string | null } = {
    tradeLicence: null,
    bin: null,
};

/** Street address and support hours are copy (they differ per locale) — see `footer.contact` in the catalogs, also placeholders. */
export const addressIsPlaceholder = true;

/**
 * Footer link tiles (Figma Footer 124:3310: Shop · Help · About). `label` is a key of
 * `footer.links` in the message catalogs; `path` is locale-independent. Pages not built yet
 * (track, policies, flash sale…) answer the styled 404 until their task lands — listed here so
 * the footer is complete from day one.
 */
export const footerColumns = [
    {
        title: 'shop',
        links: [
            { label: 'allToys', path: '/categories' },
            { label: 'byAge', path: '/age' },
            { label: 'flashSale', path: '/offers' },
            { label: 'newArrivals', path: '/new-arrivals' },
            { label: 'bestSellers', path: '/best-sellers' },
            { label: 'brands', path: '/brands' },
        ],
    },
    {
        title: 'help',
        links: [
            { label: 'track', path: '/track' },
            { label: 'delivery', path: '/pages/delivery' },
            { label: 'returns', path: '/pages/returns' },
            { label: 'faq', path: '/pages/faq' },
            { label: 'contact', path: '/pages/contact' },
        ],
    },
    {
        title: 'about',
        links: [
            { label: 'about', path: '/pages/about' },
            { label: 'safety', path: '/pages/toy-safety' },
            { label: 'blog', path: '/blog' },
            { label: 'privacy', path: '/pages/privacy' },
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
