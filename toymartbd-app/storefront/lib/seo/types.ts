// Shapes of the SEO endpoints (docs/api/seo.md §3). Hand-written to match the contract until
// @toymart/api-types carries them; client-safe (types only).

import type { Locale } from '../i18n/config';

/** Morph keys of docs/api/seo.md §1. Blog articles and buying guides share `blog_post`; blog archives are `blog_category|tag|author`. */
export const SEO_TYPES = ['product', 'category', 'brand', 'page', 'blog_post', 'blog_category', 'blog_tag', 'blog_author'] as const;
export type SeoType = (typeof SEO_TYPES)[number];

export interface SeoLocaleText {
    /** A complete `<title>` (already carries the site name): render as `title.absolute`. */
    title: string;
    description: string;
    og_title: string;
    og_description: string;
}

/** `GET /storefront/seo/{type}/{slug}` — every text value is already the effective one. */
export interface SeoDocument {
    type: SeoType;
    slug: string;
    path: string;
    robots: { index: boolean; follow: boolean };
    canonical_url: string | null;
    sitemap_exclude: boolean;
    twitter_card: 'summary_large_image' | 'summary';
    og_image: string | null;
    en: SeoLocaleText;
    bn: SeoLocaleText;
    updated_at: string | null;
}

export function seoText(doc: SeoDocument, locale: Locale): SeoLocaleText {
    return doc[locale];
}

/** One entry of `GET /storefront/redirects` (locale-independent paths). */
export interface RedirectEntry {
    /** Manual redirect id (hit counter); null for automatic slug-history redirects. */
    id: number | null;
    from: string;
    /** A path starting with `/`, or an absolute https URL. */
    to: string;
    status: 301 | 302;
}

export interface RedirectMap {
    redirects: RedirectEntry[];
}
