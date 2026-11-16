import type { Metadata } from 'next';

import { htmlLang, localizedPath, locales, type Locale } from '../i18n/config';
import { getMessages } from '../i18n';
import { indexing, hrefFor, type ListingState } from '../listing/state';
import type { StoreBranding } from '../site-config';
import { getStoreInfo } from '../store-info';
import { siteUrl } from './site';
import type { SeoDocument } from './types';

// The storefront's ONE metadata builder (docs/api/seo.md §4). Every template's generateMetadata
// returns one of the per-template functions below, so every page carries — in both locales — a
// canonical, reciprocal hreflang (en at the root, bn under /bn) plus x-default (rule 35), robots,
// per-locale Open Graph and Twitter tags. `composeMetadata` is pure and unit-tested; the async
// builders around it add one read: Site & branding from `getStoreInfo()` (React-cache()d per
// request, never throws), so EVERY page gets the owner's site name as og:site_name and the share
// image as its default Open Graph / Twitter image — a page's own image always wins. (Next.js
// replaces the layout's openGraph object with a page's, so the layout default alone cannot do it.)

export interface Robots {
    index: boolean;
    follow: boolean;
}

export interface SeoImage {
    url: string;
    width?: number | null;
    height?: number | null;
    alt?: string | null;
}

export interface PageSeoInput {
    locale: Locale;
    /** Locale-independent path, optionally with the canonical query (`/categories/blocks?age=24-48`). */
    path: string;
    /** Page title; the layout template adds the site name unless `absoluteTitle`. */
    title: string;
    absoluteTitle?: boolean;
    description?: string | null;
    robots?: Robots;
    image?: SeoImage | null;
    ogType?: 'website' | 'article';
    ogTitle?: string;
    ogDescription?: string;
    twitterCard?: 'summary_large_image' | 'summary';
    /** Absolute canonical override (seo_meta.canonical_url). Localised when it points at this site. */
    canonicalUrl?: string | null;
    article?: { publishedTime?: string | null; modifiedTime?: string | null };
    /** `og:site_name`; defaults to the catalog name. The owner's site name where the page has it. */
    siteName?: string;
}

export const INDEX: Robots = { index: true, follow: true };
export const NOINDEX_FOLLOW: Robots = { index: false, follow: true };
export const NOINDEX_NOFOLLOW: Robots = { index: false, follow: false };

/**
 * Joins a page name and the site name in every `<title>`. The API's config/seo.php owns the value
 * (`GET /storefront/seo/settings`, read by ./fetch.ts `getTitleSeparator`); this is only the
 * fallback when the API cannot be reached, and equals the API's default.
 */
export const DEFAULT_TITLE_SEPARATOR = ' | ';

/** The separator from a `GET /storefront/seo/settings` body; the default for anything malformed. */
export function titleSeparatorOf(body: unknown): string {
    const value = typeof body === 'object' && body !== null ? (body as { title_separator?: unknown }).title_separator : undefined;
    return typeof value === 'string' && value.trim() !== '' && value.length <= 10 ? value : DEFAULT_TITLE_SEPARATOR;
}

/** The layout's title template: `%s | Toy Mart BD`. */
export function titleTemplate(siteName: string, separator: string = DEFAULT_TITLE_SEPARATOR): string {
    return `%s${separator}${siteName}`;
}

/** `og:locale` per locale (Open Graph wants language_TERRITORY). */
export const ogLocale: Record<Locale, string> = { en: 'en_US', bn: 'bn_BD' };

/** Absolute URL on this site for a public path. */
export function absoluteUrl(publicPath: string): string {
    return new URL(publicPath, siteUrl()).toString();
}

/** hreflang map: en, bn and x-default (→ en) for a locale-independent path (query kept). */
export function hreflangFor(path: string): Record<string, string> {
    const languages: Record<string, string> = {};
    for (const l of locales) languages[htmlLang[l]] = localizedPath(l, path);
    languages['x-default'] = localizedPath('en', path);
    return languages;
}

/**
 * The canonical for this locale. An override pointing at this site is re-prefixed for the locale
 * (a Bangla page never canonicalises to the English one — that would drop it from the index);
 * an off-site override is used as given.
 */
export function canonicalFor(locale: Locale, path: string, override?: string | null): string {
    if (override) {
        try {
            const url = new URL(override);
            if (url.host !== siteUrl().host) return override;
            const { path: own } = splitPublic(url.pathname);
            return localizedPath(locale, `${own}${url.search}`);
        } catch {
            // Not a URL: fall through to the page's own canonical.
        }
    }
    return localizedPath(locale, path);
}

function splitPublic(pathname: string): { path: string } {
    if (pathname === '/bn') return { path: '/' };
    if (pathname.startsWith('/bn/')) return { path: pathname.slice(3) };
    return { path: pathname };
}

/** The share image as an Open Graph image (alt = the site name). */
function shareImageOf(branding: StoreBranding, locale: Locale): SeoImage | null {
    const image = branding.shareImage;
    return image ? { url: image.url, width: image.width, height: image.height, alt: branding.siteName[locale] } : null;
}

/** This request's Site & branding. Never throws: unset or unreachable is the catalog copy. */
async function siteBranding(): Promise<StoreBranding> {
    return (await getStoreInfo()).branding;
}

/**
 * Pure: the page's metadata with Site & branding defaults. `branding` null/undefined = none
 * (og:site_name is the catalog name, no default image). The page's own image always wins.
 */
export function composeMetadata(input: PageSeoInput, branding?: StoreBranding | null): Metadata {
    const { locale } = input;
    const t = getMessages(locale);
    const ownImage = input.image ?? (branding ? shareImageOf(branding, locale) : null);
    input = { ...input, image: ownImage, siteName: input.siteName ?? branding?.siteName[locale] };
    const robots = input.robots ?? INDEX;
    const canonical = canonicalFor(locale, input.path, input.canonicalUrl);
    const description = input.description ?? undefined;
    const ogTitle = input.ogTitle ?? input.title;
    const ogDescription = input.ogDescription ?? description;
    const images = input.image ? [{ url: input.image.url, ...(input.image.width ? { width: input.image.width } : {}), ...(input.image.height ? { height: input.image.height } : {}), ...(input.image.alt ? { alt: input.image.alt } : {}) }] : undefined;
    const card = input.twitterCard ?? (images ? 'summary_large_image' : 'summary');

    return {
        title: input.absoluteTitle ? { absolute: input.title } : input.title,
        ...(description ? { description } : {}),
        alternates: { canonical, languages: hreflangFor(input.path) },
        // index,follow is the default: emitted only when it is not (an indexable page carries no robots meta).
        ...(robots.index && robots.follow ? {} : { robots: { index: robots.index, follow: robots.follow } }),
        openGraph: {
            type: input.ogType ?? 'website',
            siteName: input.siteName ?? t.site.name,
            locale: ogLocale[locale],
            alternateLocale: locales.filter((l) => l !== locale).map((l) => ogLocale[l]),
            url: absoluteUrl(canonical),
            title: ogTitle,
            ...(ogDescription ? { description: ogDescription } : {}),
            ...(images ? { images } : {}),
            ...(input.ogType === 'article' && input.article
                ? {
                      ...(input.article.publishedTime ? { publishedTime: input.article.publishedTime } : {}),
                      ...(input.article.modifiedTime ? { modifiedTime: input.article.modifiedTime } : {}),
                  }
                : {}),
        },
        twitter: {
            card,
            title: ogTitle,
            ...(ogDescription ? { description: ogDescription } : {}),
            ...(images ? { images: images.map((i) => i.url) } : {}),
        },
    };
}

/** composeMetadata with this request's Site & branding — what every page template goes through. */
export async function buildMetadata(input: PageSeoInput): Promise<Metadata> {
    return composeMetadata(input, await siteBranding());
}

/** Both robots must allow: a page-level noindex (facets, search) always wins over the SEO document. */
function bothAllow(a: Robots, b: Robots | undefined): Robots {
    return b ? { index: a.index && b.index, follow: a.follow && b.follow } : a;
}

/** Overlay the admin's SEO document (effective values, both locales) onto a page's defaults. */
export function withSeoDocument(input: PageSeoInput, doc: SeoDocument | null | undefined): PageSeoInput {
    if (!doc) return input;
    const text = doc[input.locale];
    return {
        ...input,
        title: text.title || input.title,
        absoluteTitle: text.title ? true : input.absoluteTitle,
        description: text.description || input.description,
        ogTitle: text.og_title || text.title || input.ogTitle,
        ogDescription: text.og_description || text.description || input.ogDescription,
        image: doc.og_image ? { url: doc.og_image, alt: input.image?.alt ?? null } : input.image,
        twitterCard: doc.twitter_card,
        robots: bothAllow(input.robots ?? INDEX, doc.robots),
        canonicalUrl: doc.canonical_url ?? input.canonicalUrl,
    };
}

// ── Per-template builders ──────────────────────────────────────────────────────────────────────

/**
 * The root layout's metadata from Site & branding (lib/store-info.ts): the default title and its
 * template carry the site name, the description is the owner's search description (else the
 * tagline, else the catalog tagline), the share image is the default Open Graph image and the
 * rendered PNGs are the icons. Every value falls back to today's catalog copy, so an unset or
 * unreachable branding answer changes nothing.
 *
 * NOTE (Next.js metadata merging): a page that returns its own `openGraph`/`twitter` REPLACES
 * these objects whole, so this is only the default for pages without Open Graph of their own (404,
 * maintenance); every page template gets the same defaults through `buildMetadata`.
 */
export function siteMetadata(locale: Locale, branding: StoreBranding, separator: string = DEFAULT_TITLE_SEPARATOR): Metadata {
    const t = getMessages(locale);
    const name = branding.siteName[locale];
    const description = branding.description[locale] ?? branding.tagline[locale] ?? t.site.tagline;
    const image = shareImageOf(branding, locale);
    const images = image ? [{ url: image.url, width: image.width ?? undefined, height: image.height ?? undefined, alt: image.alt ?? undefined }] : undefined;
    const png = (url: string | null, size: number) => (url ? [{ url, sizes: `${size}x${size}`, type: 'image/png' }] : []);
    const icon = branding.icons ? [...png(branding.icons.png32, 32), ...png(branding.icons.png192, 192), ...png(branding.icons.png512, 512)] : [];
    const apple = branding.icons ? png(branding.icons.png180, 180) : [];

    return {
        // One separator for every title (config/seo.php via the API) — the same one the server's SEO
        // documents end with, so a page title never carries two different suffixes.
        title: { default: name, template: titleTemplate(name, separator) },
        description,
        openGraph: {
            type: 'website',
            siteName: name,
            locale: ogLocale[locale],
            title: name,
            description,
            ...(images ? { images } : {}),
        },
        twitter: { card: images ? 'summary_large_image' : 'summary', title: name, description, ...(images ? { images: images.map((i) => i.url) } : {}) },
        // No static favicon ships with the storefront: until the icon job has run there is no icon tag.
        ...(icon.length > 0 || apple.length > 0 ? { icons: { ...(icon.length > 0 ? { icon } : {}), ...(apple.length > 0 ? { apple } : {}) } } : {}),
    };
}

/** Home: `{site name}{separator}{tagline}`, the owner's description and share image when set. */
export async function homeMetadata(locale: Locale, separator: string = DEFAULT_TITLE_SEPARATOR): Promise<Metadata> {
    const t = getMessages(locale);
    const branding = await siteBranding();
    return composeMetadata(
        {
            locale,
            path: '/',
            title: `${branding.siteName[locale]}${separator}${branding.tagline[locale] ?? t.site.tagline}`,
            absoluteTitle: true,
            description: branding.description[locale] ?? t.home.metaDescription,
        },
        branding,
    );
}

/** An indexable page with no SEO document of its own (category/brand indexes, about, delivery, track). */
export async function staticMetadata(locale: Locale, path: string, title: string, description?: string | null, robots: Robots = INDEX): Promise<Metadata> {
    return buildMetadata({ locale, path, title, description, robots });
}

export interface ListingPageInput {
    locale: Locale;
    /** Locale-independent listing path (`/categories/blocks`). */
    path: string;
    state: ListingState;
    title: string;
    description?: string | null;
    image?: SeoImage | null;
    seo?: SeoDocument | null;
    /**
     * The listing the page renders, when the URL carries a cursor. The API answers a cursor it
     * cannot decode with the FIRST page (`prev_cursor: null`), so `?cursor=<junk>` would otherwise
     * be an indexable, self-canonical duplicate of the bare listing — an infinite URL space.
     */
    listing?: { prevCursor: string | null } | null;
}

/**
 * Category, brand and all-toys listings. Pagination SEO + facets (§A7.5, §A8): the bare listing,
 * a single-facet view and their later pages are indexable and self-canonical (the cursor stays in
 * the canonical); several facets or a non-default sort are `noindex, follow` with the canonical
 * on the bare listing. hreflang carries the same query in both locales.
 */
export async function listingPageMetadata(input: ListingPageInput): Promise<Metadata> {
    const staleCursor = input.state.cursor !== null && input.listing != null && input.listing.prevCursor === null;
    if (staleCursor) {
        // A cursor that resolved to page 1: canonicalise to the cursor-less view and keep it out of the index.
        const first = indexing({ ...input.state, cursor: null }).canonical;
        return buildMetadata({ ...withSeoDocument({ locale: input.locale, path: hrefFor(input.path, first), title: input.title, description: input.description, image: input.image }, input.seo), robots: NOINDEX_FOLLOW, canonicalUrl: null });
    }
    const { indexable, canonical } = indexing(input.state);
    const path = hrefFor(input.path, canonical);
    const base: PageSeoInput = { locale: input.locale, path, title: input.title, description: input.description, image: input.image, robots: indexable ? INDEX : NOINDEX_FOLLOW };
    const merged = withSeoDocument(base, input.seo);
    // A facet or later page keeps its own canonical; the admin override applies to the bare listing only.
    const bare = path === input.path;
    return buildMetadata({ ...merged, canonicalUrl: bare ? merged.canonicalUrl : null });
}

export interface ProductMetaInput {
    locale: Locale;
    slug: string;
    name: string;
    description: string;
    image?: SeoImage | null;
    seo?: SeoDocument | null;
}

/** The product page. The canonical never carries `?variant=`: every variant is one page (rule 37). */
export async function productMetadata(input: ProductMetaInput): Promise<Metadata> {
    return buildMetadata(withSeoDocument({ locale: input.locale, path: `/products/${input.slug}`, title: input.name, description: input.description, image: input.image }, input.seo));
}

export interface ContentMetaInput {
    locale: Locale;
    /** `/{slug}` (a CMS page), `/blog/{slug}`, `/guides/{slug}`. */
    path: string;
    title: string;
    description?: string | null;
    image?: SeoImage | null;
    seo?: SeoDocument | null;
    /** Posts and guides are articles; CMS pages are websites. */
    kind?: 'page' | 'article';
    publishedTime?: string | null;
    modifiedTime?: string | null;
}

/** CMS pages, blog posts and buying guides. */
export async function contentMetadata(input: ContentMetaInput): Promise<Metadata> {
    const article = input.kind === 'article';
    return buildMetadata(
        withSeoDocument(
            {
                locale: input.locale,
                path: input.path,
                title: input.title,
                description: input.description,
                image: input.image,
                ogType: article ? 'article' : 'website',
                ...(article ? { article: { publishedTime: input.publishedTime, modifiedTime: input.modifiedTime } } : {}),
            },
            input.seo,
        ),
    );
}

/** Search results: never indexed (thin, infinite URL space), links still followed. Canonical /search. */
export async function searchMetadata(locale: Locale, title: string, description?: string | null): Promise<Metadata> {
    return buildMetadata({ locale, path: '/search', title, description, robots: NOINDEX_FOLLOW });
}

/**
 * Cart, checkout, account and order pages: `noindex, nofollow` (cart: `follow` — it links to
 * products). Still carries hreflang + x-default, like every page.
 */
export async function privateMetadata(locale: Locale, path: string, title: string, options: { description?: string | null; follow?: boolean } = {}): Promise<Metadata> {
    return buildMetadata({ locale, path, title, description: options.description, robots: options.follow ? NOINDEX_FOLLOW : NOINDEX_NOFOLLOW });
}

/** Order tracking: a public, indexable utility page (the lookup itself is a POST, never in the URL). */
export async function trackMetadata(locale: Locale, title: string, description?: string | null): Promise<Metadata> {
    return buildMetadata({ locale, path: '/track', title, description });
}

/** 404 and maintenance: noindex, and no canonical (a 404 has no URL of its own to claim). */
export function noindexMetadata(title: string): Metadata {
    return { title, robots: { index: false, follow: false } };
}
