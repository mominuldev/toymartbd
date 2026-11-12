import { localizedPath, type Locale } from '../i18n/config';
import { siteUrl } from '../seo';
import type { StorefrontProduct } from './variants';

// Structured data for the product page: schema.org Product with one Offer per active variant
// (price and availability exactly as the API sends them) and the BreadcrumbList with the full
// path (Figma Breadcrumb: JSON-LD always carries the full path, even when the UI collapses it).

const availability = {
    in_stock: 'https://schema.org/InStock',
    low_stock: 'https://schema.org/LimitedAvailability',
    out_of_stock: 'https://schema.org/OutOfStock',
} as const;

export function productJsonLd(product: StorefrontProduct, locale: Locale, labels: { home: string }): Array<Record<string, unknown>> {
    const base = siteUrl();
    const abs = (path: string): string => new URL(path, base).toString();
    const name = locale === 'bn' && product.name_bn ? product.name_bn : product.name;
    const description = (locale === 'bn' ? (product.short_description_bn ?? product.short_description) : product.short_description) ?? undefined;
    const url = abs(localizedPath(locale, `/products/${product.slug}`));

    const offers = product.variants.map((variant) => ({
        '@type': 'Offer',
        sku: variant.sku,
        url: `${url}?variant=${variant.id}`,
        price: variant.price,
        priceCurrency: 'BDT',
        availability: availability[variant.stock_state],
        itemCondition: 'https://schema.org/NewCondition',
    }));

    const productLd: Record<string, unknown> = {
        '@context': 'https://schema.org',
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
    const breadcrumbLd = {
        '@context': 'https://schema.org',
        '@type': 'BreadcrumbList',
        itemListElement: crumbs.map((c, i) => ({ '@type': 'ListItem', position: i + 1, name: c.name, item: abs(localizedPath(locale, c.path)) })),
    };

    return [productLd, breadcrumbLd];
}

/** JSON for a <script type="application/ld+json">, with `<` escaped so no string can close the tag. */
export function jsonLdScript(data: unknown): string {
    return JSON.stringify(data).replace(/</g, '\\u003c');
}
