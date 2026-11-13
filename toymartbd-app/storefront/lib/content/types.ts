import type { Schema } from '@toymart/api-types';

import type { MenuItem, MenuLink } from '../category-tree';
import { localizedPath, type Locale } from '../i18n/config';

// Client-safe shapes and pure helpers for the CMS content the storefront reads: static pages,
// menus, banners, testimonials (GET /api/v1/storefront/{pages,menus,banners,testimonials}).
// The fetches are server-only (./api.ts). Every link is resolved by Laravel to a locale-free
// path; the storefront only adds the locale prefix (rule 1: no URL is built from an id here).

export type CmsPage = Schema<'StorefrontPage'>;
export type CmsPageSummary = Schema<'StorefrontPageSummary'>;
export type Banner = Schema<'StorefrontBanner'>;
export type Testimonial = Schema<'StorefrontTestimonial'>;
export type BannerPlacement = Banner['placement'];
export type BannerContext = 'general' | 'cart' | 'checkout';
export type PageTemplate = CmsPage['template'];

export type MenuLocation = 'header' | 'footer' | 'mobile';

/** One menu entry as the API sends it; `children` arrives untyped and is validated here. */
export interface CmsMenuItem {
    label: string;
    label_bn: string | null;
    type: string;
    href: string | null;
    open_in_new_tab: boolean;
    children: CmsMenuItem[];
}

/** `null` for a location with no published menu: the storefront falls back to its own menu. */
export type CmsMenus = Record<MenuLocation, CmsMenuItem[] | null>;

export const EMPTY_MENUS: CmsMenus = { header: null, footer: null, mobile: null };

export interface Localised {
    text: string;
    /** `en` when a Bangla page shows the English fallback. */
    lang?: 'en';
}

/** Bangla value with an English fallback (marked `lang="en"`), like brandName/categoryName. */
export function localised(locale: Locale, en: string, bn: string | null | undefined): Localised {
    if (locale !== 'bn') return { text: en };
    return bn && bn.trim() !== '' ? { text: bn } : { text: en, lang: 'en' };
}

/** Nullable variant: null when neither locale has a value. */
export function localisedOptional(locale: Locale, en: string | null | undefined, bn: string | null | undefined): Localised | null {
    if (locale === 'bn' && bn && bn.trim() !== '') return { text: bn };
    if (en && en.trim() !== '') return locale === 'bn' ? { text: en, lang: 'en' } : { text: en };
    return null;
}

// ── Links ───────────────────────────────────────────────────────────────────────────────────

/** An absolute http(s) URL: rendered as is, never locale-prefixed. */
export function isExternalHref(href: string): boolean {
    return /^https?:\/\//i.test(href);
}

/** A site path the storefront may prefix: `/…`, never protocol-relative `//…`. */
export function isSitePath(href: string): boolean {
    return href.startsWith('/') && !href.startsWith('//');
}

export interface LinkAttrs {
    href: string;
    target?: '_blank';
    rel?: string;
}

/**
 * The anchor attributes for a CMS href: a site path gets the locale prefix; an https URL stays
 * absolute with rel="noopener" (plus target=_blank when the item asks for a new tab). Anything
 * else (javascript:, //host, garbage) is refused — null, and the caller renders no link.
 */
export function linkAttrs(locale: Locale, href: string | null | undefined, newTab = false): LinkAttrs | null {
    if (!href) return null;
    if (isSitePath(href)) return newTab ? { href: localizedPath(locale, href), target: '_blank', rel: 'noopener' } : { href: localizedPath(locale, href) };
    if (/^https:\/\//i.test(href)) return newTab ? { href, target: '_blank', rel: 'noopener noreferrer' } : { href, rel: 'noopener' };
    return null;
}

// ── Menus ───────────────────────────────────────────────────────────────────────────────────

function normaliseItem(value: unknown, depth: number): CmsMenuItem | null {
    if (typeof value !== 'object' || value === null || depth > 3) return null;
    const raw = value as Record<string, unknown>;
    if (typeof raw.label !== 'string' || raw.label.trim() === '' || typeof raw.type !== 'string') return null;
    const href = typeof raw.href === 'string' && raw.href !== '' ? raw.href : null;
    return {
        label: raw.label,
        label_bn: typeof raw.label_bn === 'string' && raw.label_bn !== '' ? raw.label_bn : null,
        type: raw.type,
        href,
        open_in_new_tab: raw.open_in_new_tab === true,
        children: Array.isArray(raw.children) ? raw.children.map((c) => normaliseItem(c, depth + 1)).filter((c): c is CmsMenuItem => c !== null) : [],
    };
}

/** Validates the untrusted `GET /menus` body. A malformed location reads as "no menu" (fallback). */
export function normaliseMenus(body: unknown): CmsMenus {
    if (typeof body !== 'object' || body === null) return EMPTY_MENUS;
    const raw = ('data' in body && typeof (body as { data: unknown }).data === 'object' ? (body as { data: unknown }).data : body) as Record<string, unknown>;
    const pick = (location: MenuLocation): CmsMenuItem[] | null => {
        const menu = raw?.[location];
        if (typeof menu !== 'object' || menu === null || !Array.isArray((menu as { items?: unknown }).items)) return null;
        return ((menu as { items: unknown[] }).items).map((i) => normaliseItem(i, 1)).filter((i): i is CmsMenuItem => i !== null);
    };
    return { header: pick('header'), footer: pick('footer'), mobile: pick('mobile') };
}

function toMenuLink(item: CmsMenuItem, locale: Locale): MenuLink | null {
    if (!item.href || (!isSitePath(item.href) && !isExternalHref(item.href))) return null;
    const label = localised(locale, item.label, item.label_bn);
    const link: MenuLink = { label: label.text, path: item.href };
    if (label.lang) link.lang = label.lang;
    if (isExternalHref(item.href)) link.external = true;
    if (item.open_in_new_tab) link.newTab = true;
    return link;
}

const splitInto = <T,>(items: T[], count: number): T[][] => {
    if (items.length === 0) return [];
    const per = Math.ceil(items.length / count);
    const out: T[][] = [];
    for (let i = 0; i < items.length; i += per) out.push(items.slice(i, i + per));
    return out;
};

/**
 * The published header menu as the mega-menu view model. Level 1 is the bar, level 2 the panel's
 * group headings, level 3 their links. A `category_tree` item is replaced, in place, by the
 * category menu (the bar the storefront showed before the builder existed). A heading without a
 * link (`none`) keeps its place with its children as plain links.
 */
export function headerMenu(items: CmsMenuItem[], locale: Locale, categoryMenu: MenuItem[], viewAll: (label: string) => string): MenuItem[] {
    const out: MenuItem[] = [];
    items.forEach((item, index) => {
        if (item.type === 'category_tree') {
            out.push(...categoryMenu);
            return;
        }
        const groups = item.children
            .map((child) => {
                const links = child.children.map((l) => toMenuLink(l, locale)).filter((l): l is MenuLink => l !== null);
                // A heading without its own link (`none`) points at its first link, so every entry stays a real anchor.
                const heading = toMenuLink(child, locale) ?? (links[0] ? retarget(child, locale, links[0]) : null);
                return heading ? { heading, links } : null;
            })
            .filter((g): g is { heading: MenuLink; links: MenuLink[] } => g !== null);
        const own = toMenuLink(item, locale);
        const label = own ?? (groups[0] ? retarget(item, locale, groups[0].heading) : null);
        if (!label) return;
        out.push({
            id: `cms-${index}`,
            label,
            columns: splitInto(groups, 3),
            viewAll: own && groups.length > 0 ? { ...own, label: viewAll(own.label) } : null,
        });
    });
    return out;
}

/** The item's own label on another link's target. */
function retarget(item: CmsMenuItem, locale: Locale, target: MenuLink): MenuLink {
    const link: MenuLink = { ...localisedLink(item, locale), path: target.path };
    if (target.external) link.external = true;
    if (target.newTab) link.newTab = true;
    return link;
}

function localisedLink(item: CmsMenuItem, locale: Locale): Omit<MenuLink, 'path'> {
    const label = localised(locale, item.label, item.label_bn);
    return label.lang ? { label: label.text, lang: label.lang } : { label: label.text };
}

export interface FooterMenuColumn {
    heading: Localised;
    links: Array<MenuLink>;
}

/** Footer columns from the published footer menu: each top-level item is a column. */
export function footerMenu(items: CmsMenuItem[], locale: Locale): FooterMenuColumn[] {
    return items
        .map((item) => {
            const links = (item.children.length > 0 ? item.children : [item]).map((c) => toMenuLink(c, locale)).filter((l): l is MenuLink => l !== null);
            return { heading: localised(locale, item.label, item.label_bn), links };
        })
        .filter((c) => c.links.length > 0);
}

/** Flat list for the mobile menu: top-level items, each with its links (category_tree → /categories). */
export function mobileMenu(items: CmsMenuItem[], locale: Locale): Array<{ link: MenuLink | null; label: Localised; children: MenuLink[] }> {
    return items.map((item) => ({
        link: toMenuLink(item, locale),
        label: localised(locale, item.label, item.label_bn),
        children: item.children.map((c) => toMenuLink(c, locale)).filter((l): l is MenuLink => l !== null),
    }));
}

// ── Pages ───────────────────────────────────────────────────────────────────────────────────

export const PAGE_SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

/** The template to render; an FAQ page without questions renders as a default page. */
export function pageTemplate(page: Pick<CmsPage, 'template' | 'faq_items'>): PageTemplate {
    if (page.template === 'faq') return page.faq_items.length > 0 ? 'faq' : 'default';
    return page.template === 'sidebar' ? 'sidebar' : 'default';
}

/** The page body in the requested locale; a missing Bangla body falls back to English (`lang="en"`). */
export function pageBody(page: Pick<CmsPage, 'content' | 'content_bn'>, locale: Locale): { html: string | null; lang?: 'en' } {
    if (locale === 'bn') {
        if (page.content_bn) return { html: page.content_bn };
        return page.content ? { html: page.content, lang: 'en' } : { html: null };
    }
    return { html: page.content };
}

export interface Heading {
    id: string;
    text: string;
}

/** h2 headings get stable ids (`section-1` …) so the sidebar template can link to them. */
export function withHeadingIds(html: string): { html: string; headings: Heading[] } {
    const headings: Heading[] = [];
    const out = html.replace(/<h2(\s[^>]*)?>([\s\S]*?)<\/h2>/gi, (_m, _attrs: string | undefined, inner: string) => {
        const id = `section-${headings.length + 1}`;
        const text = inner.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&').trim();
        headings.push({ id, text });
        return `<h2 id="${id}">${inner}</h2>`;
    });
    return { html: out, headings };
}

export interface PageSection {
    id: string;
    heading: string;
    /** The section's HTML after its h2 (already sanitised by the caller). */
    html: string;
}

/**
 * Split a body at its h2 headings (the sidebar template, Figma Privacy 166:9992: one numbered card
 * per section). `intro` is whatever precedes the first h2.
 */
export function splitSections(html: string): { intro: string; sections: PageSection[] } {
    const parts = html.split(/<h2(?:\s[^>]*)?>([\s\S]*?)<\/h2>/i);
    const intro = (parts[0] ?? '').trim();
    const sections: PageSection[] = [];
    for (let i = 1; i < parts.length; i += 2) {
        const heading = (parts[i] ?? '').replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&').trim();
        sections.push({ id: `section-${sections.length + 1}`, heading, html: (parts[i + 1] ?? '').trim() });
    }
    return { intro, sections };
}

// ── Banners ─────────────────────────────────────────────────────────────────────────────────

/**
 * What may render where (rule 25b). Cart and checkout show only the info strip, text-only, and a
 * link only when it points at a store page. Laravel enforces the same rule; this is the second
 * line so a misconfigured API can never put an upsell on checkout.
 */
export function bannerAllowed(banner: Pick<Banner, 'placement' | 'link_url' | 'image'>, context: BannerContext): boolean {
    if (context === 'general') return true;
    return banner.placement === 'info_strip';
}

export function safeInfoStripLink(href: string | null): string | null {
    return href && /^\/pages\/[a-z0-9]+(?:-[a-z0-9]+)*\/?$/.test(href) ? href : null;
}

/** localStorage key for a dismissed popup (per banner, so a new popup shows again). */
export const popupDismissKey = (id: number) => `tm-popup-dismissed:${id}`;
