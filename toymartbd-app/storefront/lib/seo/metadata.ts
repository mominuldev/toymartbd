import type { Metadata } from 'next';

import { htmlLang, localizedPath, locales, type Locale } from '../i18n/config';
import { getMessages } from '../i18n';
import { indexing, hrefFor, type ListingState } from '../listing/state';
import { siteUrl } from './site';
import type { SeoDocument } from './types';

// The storefront's ONE metadata builder (docs/api/seo.md §4). Every template's generateMetadata
// returns one of the per-template functions below, so every page carries — in both locales — a
// canonical, reciprocal hreflang (en at the root, bn under /bn) plus x-default (rule 35), robots,
// per-locale Open Graph and Twitter tags. Pure: no fetch here (see ./fetch.ts), so it is unit-tested.

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

export function buildMetadata(input: PageSeoInput): Metadata {
    const { locale } = input;
    const t = getMessages(locale);
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
            siteName: t.site.name,
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

export function homeMetadata(locale: Locale, separator: string = DEFAULT_TITLE_SEPARATOR): Metadata {
    const t = getMessages(locale);
    return buildMetadata({ locale, path: '/', title: `${t.site.name}${separator}${t.site.tagline}`, absoluteTitle: true, description: t.home.metaDescription });
}

/** An indexable page with no SEO document of its own (category/brand indexes, about, delivery, track). */
export function staticMetadata(locale: Locale, path: string, title: string, description?: string | null, robots: Robots = INDEX): Metadata {
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
}

/**
 * Category, brand and all-toys listings. Pagination SEO + facets (§A7.5, §A8): the bare listing,
 * a single-facet view and their later pages are indexable and self-canonical (the cursor stays in
 * the canonical); several facets or a non-default sort are `noindex, follow` with the canonical
 * on the bare listing. hreflang carries the same query in both locales.
 */
export function listingPageMetadata(input: ListingPageInput): Metadata {
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
export function productMetadata(input: ProductMetaInput): Metadata {
    return buildMetadata(withSeoDocument({ locale: input.locale, path: `/products/${input.slug}`, title: input.name, description: input.description, image: input.image }, input.seo));
}

export interface ContentMetaInput {
    locale: Locale;
    /** `/pages/{slug}`, `/blog/{slug}`, `/guides/{slug}`. */
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
export function contentMetadata(input: ContentMetaInput): Metadata {
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
export function searchMetadata(locale: Locale, title: string, description?: string | null): Metadata {
    return buildMetadata({ locale, path: '/search', title, description, robots: NOINDEX_FOLLOW });
}

/**
 * Cart, checkout, account and order pages: `noindex, nofollow` (cart: `follow` — it links to
 * products). Still carries hreflang + x-default, like every page.
 */
export function privateMetadata(locale: Locale, path: string, title: string, options: { description?: string | null; follow?: boolean } = {}): Metadata {
    return buildMetadata({ locale, path, title, description: options.description, robots: options.follow ? NOINDEX_FOLLOW : NOINDEX_NOFOLLOW });
}

/** Order tracking: a public, indexable utility page (the lookup itself is a POST, never in the URL). */
export function trackMetadata(locale: Locale, title: string, description?: string | null): Metadata {
    return buildMetadata({ locale, path: '/track', title, description });
}

/** 404 and maintenance: noindex, and no canonical (a 404 has no URL of its own to claim). */
export function noindexMetadata(title: string): Metadata {
    return { title, robots: { index: false, follow: false } };
}
