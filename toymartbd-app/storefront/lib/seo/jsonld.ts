import { getMessages } from '../i18n';
import { localizedPath, type Locale } from '../i18n/config';
import type { StorefrontProduct } from '../product/variants';
import { contact, organization, social } from '../site-config';
import { siteUrl } from './site';

// schema.org builders for every template (docs/api/seo.md §4). Pure and client-safe. Each output
// is checked by ./validate.ts against Google's Rich Results required properties in the unit tests.
// Text is always the page's locale: Bangla `name` / `description` on /bn/ (P-06c).

export type JsonLdNode = Record<string, unknown>;

const CONTEXT = 'https://schema.org';

const abs = (publicPath: string): string => new URL(publicPath, siteUrl()).toString();
const pageUrl = (locale: Locale, path: string): string => abs(localizedPath(locale, path));

const availability = {
    in_stock: 'https://schema.org/InStock',
    low_stock: 'https://schema.org/LimitedAvailability',
    out_of_stock: 'https://schema.org/OutOfStock',
} as const;

// ── Product ─────────────────────────────────────────────────────────────────────────────────────

/**
 * Product with one Offer per active variant (price, BDT, availability exactly as the API sends
 * them) and — only when there are approved reviews (`rating_count` counts approved ones only) —
 * AggregateRating. Returned with the BreadcrumbList carrying the full category path.
 */
export function productJsonLd(product: StorefrontProduct, locale: Locale, labels: { home: string }): JsonLdNode[] {
    const name = locale === 'bn' && product.name_bn ? product.name_bn : product.name;
    const description = (locale === 'bn' ? (product.short_description_bn ?? product.short_description) : product.short_description) ?? undefined;
    const url = pageUrl(locale, `/products/${product.slug}`);

    const offers = product.variants.map((variant) => ({
        '@type': 'Offer',
        sku: variant.sku,
        url: `${url}?variant=${variant.id}`,
        price: variant.price,
        priceCurrency: 'BDT',
        availability: availability[variant.stock_state],
        itemCondition: 'https://schema.org/NewCondition',
    }));

    const productLd: JsonLdNode = {
        '@context': CONTEXT,
        '@type': 'Product',
        name,
        url,
        sku: product.variants.find((v) => v.id === product.default_variant_id)?.sku ?? product.variants[0]?.sku,
        ...(description ? { description } : {}),
        ...(product.images.length > 0 ? { image: product.images.map((i) => i.url) } : {}),
        ...(product.brand ? { brand: { '@type': 'Brand', name: product.brand.name } } : {}),
        ...(product.rating_avg !== null && product.rating_count > 0
            ? { aggregateRating: { '@type': 'AggregateRating', ratingValue: product.rating_avg, reviewCount: product.rating_count, bestRating: '5', worstRating: '1' } }
            : {}),
        offers: offers.length === 1 ? offers[0] : offers,
    };

    const crumbs = [
        { name: labels.home, path: '/' },
        ...product.breadcrumbs.map((c) => ({ name: locale === 'bn' && c.name_bn ? c.name_bn : c.name, path: `/categories/${c.slug}` })),
        { name, path: `/products/${product.slug}` },
    ];

    return [productLd, breadcrumbJsonLd(locale, crumbs)];
}

// ── BreadcrumbList ──────────────────────────────────────────────────────────────────────────────

/** Full path, Home first, the current page last (JSON-LD always carries it, even when the UI collapses). */
export function breadcrumbJsonLd(locale: Locale, crumbs: ReadonlyArray<{ name: string; path: string }>): JsonLdNode {
    return {
        '@context': CONTEXT,
        '@type': 'BreadcrumbList',
        itemListElement: crumbs.map((c, i) => ({ '@type': 'ListItem', position: i + 1, name: c.name, item: pageUrl(locale, c.path) })),
    };
}

// ── Organization + WebSite (home) ───────────────────────────────────────────────────────────────

/**
 * The business. Contact values still marked `placeholder: true` in site-config are NOT emitted
 * (a made-up number in structured data is worse than none); socials only when confirmed.
 */
export function organizationJsonLd(locale: Locale): JsonLdNode {
    const t = getMessages(locale);
    const sameAs = social.flatMap((s) => (s.href ? [s.href] : []));
    const contactPoint = [contact.hotline, contact.email].some((c) => !c.placeholder)
        ? [
              {
                  '@type': 'ContactPoint',
                  contactType: 'customer service',
                  areaServed: 'BD',
                  availableLanguage: ['en', 'bn'],
                  ...(contact.hotline.placeholder ? {} : { telephone: contact.hotline.href.replace(/^tel:/, '') }),
                  ...(contact.email.placeholder ? {} : { email: contact.email.href.replace(/^mailto:/, '') }),
              },
          ]
        : [];
    return {
        '@context': CONTEXT,
        '@type': 'Organization',
        '@id': `${abs('/')}#organization`,
        name: t.site.name,
        url: abs('/'),
        ...(organization.logo.placeholder ? {} : { logo: abs(organization.logo.path) }),
        ...(organization.legalName.placeholder ? {} : { legalName: organization.legalName.value }),
        ...(sameAs.length > 0 ? { sameAs } : {}),
        ...(contactPoint.length > 0 ? { contactPoint } : {}),
    };
}

/** The site with a sitelinks search box: SearchAction into the locale's search page. */
export function websiteJsonLd(locale: Locale): JsonLdNode {
    const t = getMessages(locale);
    return {
        '@context': CONTEXT,
        '@type': 'WebSite',
        '@id': `${pageUrl(locale, '/')}#website`,
        name: t.site.name,
        url: pageUrl(locale, '/'),
        inLanguage: locale,
        publisher: { '@id': `${abs('/')}#organization` },
        potentialAction: {
            '@type': 'SearchAction',
            target: { '@type': 'EntryPoint', urlTemplate: `${pageUrl(locale, '/search')}?q={search_term_string}` },
            'query-input': 'required name=search_term_string',
        },
    };
}

// ── Article (blog posts, buying guides) ─────────────────────────────────────────────────────────

export interface ArticleInput {
    locale: Locale;
    path: string;
    headline: string;
    description?: string | null;
    images: string[];
    datePublished: string;
    dateModified?: string | null;
    author?: { name: string; url?: string | null } | null;
    /** `Article` by default; `BlogPosting` for blog posts if preferred. */
    type?: 'Article' | 'BlogPosting';
}

export function articleJsonLd(input: ArticleInput): JsonLdNode {
    const t = getMessages(input.locale);
    return {
        '@context': CONTEXT,
        '@type': input.type ?? 'Article',
        headline: input.headline.slice(0, 110),
        url: pageUrl(input.locale, input.path),
        mainEntityOfPage: pageUrl(input.locale, input.path),
        inLanguage: input.locale,
        ...(input.description ? { description: input.description } : {}),
        ...(input.images.length > 0 ? { image: input.images.map((i) => abs(i)) } : {}),
        datePublished: input.datePublished,
        dateModified: input.dateModified ?? input.datePublished,
        author: input.author
            ? { '@type': 'Person', name: input.author.name, ...(input.author.url ? { url: abs(input.author.url) } : {}) }
            : { '@type': 'Organization', name: t.site.name, url: abs('/') },
        publisher: { '@type': 'Organization', '@id': `${abs('/')}#organization`, name: t.site.name },
    };
}

// ── FAQPage ─────────────────────────────────────────────────────────────────────────────────────

/** Questions and answers as plain text (answers may carry simple HTML; Google allows a subset). */
export function faqPageJsonLd(items: ReadonlyArray<{ question: string; answer: string }>): JsonLdNode {
    return {
        '@context': CONTEXT,
        '@type': 'FAQPage',
        mainEntity: items.map((item) => ({ '@type': 'Question', name: item.question, acceptedAnswer: { '@type': 'Answer', text: item.answer } })),
    };
}

/** JSON for a <script type="application/ld+json">, with `<` escaped so no string can close the tag. */
export function jsonLdScript(data: unknown): string {
    return JSON.stringify(data).replace(/</g, '\\u003c');
}
