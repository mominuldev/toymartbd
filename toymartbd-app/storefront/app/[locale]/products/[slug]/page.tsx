import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { cache } from 'react';

import { DeliveryEstimator } from '@/components/delivery/DeliveryEstimator';
import { Icon } from '@/components/icons/Icon';
import { PageShell } from '@/components/PageShell';
import { AddToCartBar } from '@/components/product/AddToCartBar';
import { ProductGallery } from '@/components/product/ProductGallery';
import { ProductInfoPanel } from '@/components/product/ProductInfoPanel';
import { ProductReviews, type ReviewOutcome } from '@/components/product/ProductReviews';
import { AgeBlock, FlashSaleStrip, PriceBlock, ProductTitle, RatingRow, StockLine } from '@/components/product/ProductSummary';
import { variantFlash } from '@/lib/flash-sale/format';
import { PurchasePanel } from '@/components/product/PurchasePanel';
import { RecentlyViewed } from '@/components/product/RecentlyViewed';
import { RelatedProducts } from '@/components/product/RelatedProducts';
import { TrustStrip } from '@/components/product/TrustStrip';
import { VariantSelector } from '@/components/product/VariantSelector';
import { categoryPath } from '@/lib/category-tree';
import { format, getMessages, isLocale, localizedPath, type Locale } from '@/lib/i18n';
import { formatPrice } from '@/lib/listing/format';
import { getDeliveryRates, getProduct } from '@/lib/product/fetch';
import { beforeCutoff, cutoffLabel } from '@/lib/product/format';
import { JsonLd } from '@/components/seo/JsonLd';
import { productJsonLd } from '@/lib/seo/jsonld';
import { getReviews, validCursor } from '@/lib/reviews/fetch';
import { reviewMessages } from '@/lib/reviews/messages';
import { isExplicitVariant, resolveVariant, variantHref, variantSummary, VARIANT_PARAM, type StorefrontProduct } from '@/lib/product/variants';
import { getSeo } from '@/lib/seo/fetch';
import { productMetadata } from '@/lib/seo/metadata';
import '@/components/product/product.css';

// Product detail page (P4-STO-05; Figma "Product detail" 135:1022 at 1440, 128:625 at 390, plus the
// per-tab / per-accordion state screens). GET /storefront/products/{slug} is tag-cached under
// `products` + `product:{slug}` (docs/api/revalidation.md). The selected variant lives in the URL
// (`?variant=<id>`), so every state is server-rendered, shareable and works with JavaScript off;
// the client islands only add the zoom lens, the sticky bar and in-place tabs. Unknown slug → 404.

interface Props {
    params: Promise<{ locale: string; slug: string }>;
    searchParams: Promise<Record<string, string | string[] | undefined>>;
}

const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

const loadProduct = cache(async (slug: string, locale: Locale) => (SLUG.test(slug) && slug.length <= 92 ? getProduct(slug, locale) : null));

function productName(product: StorefrontProduct, locale: Locale): { text: string; lang?: 'en' } {
    if (locale === 'bn' && product.name_bn) return { text: product.name_bn };
    return locale === 'bn' ? { text: product.name, lang: 'en' } : { text: product.name };
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale, slug } = await params;
    if (!isLocale(locale)) return {};
    const [product, seo] = await Promise.all([loadProduct(slug, locale), getSeo('product', slug)]);
    if (!product) return {};
    const t = getMessages(locale);
    const name = productName(product, locale).text;
    const variant = resolveVariant(product, undefined);
    const description =
        (locale === 'bn' ? (product.short_description_bn ?? product.short_description) : product.short_description) ??
        format(t.pdp.metaDescription, { name, price: variant ? formatPrice(variant.price) : '' });
    const image = product.images[0];
    // The canonical never carries ?variant=: every variant is one page for search (rule 37 slugs).
    return productMetadata({
        locale,
        slug: product.slug,
        name,
        description,
        image: image ? { url: image.url, width: image.width, height: image.height, alt: (locale === 'bn' ? image.alt_bn : image.alt) ?? name } : null,
        seo,
    });
}

export default async function ProductPage({ params, searchParams }: Props) {
    const { locale, slug } = await params;
    if (!isLocale(locale)) notFound();
    const [product, query, rates] = await Promise.all([loadProduct(slug, locale), searchParams, getDeliveryRates(locale)]);
    if (!product) notFound();
    const variant = resolveVariant(product, query[VARIANT_PARAM]);
    if (!variant) notFound();
    // Approved reviews + summary (P4-CAT-12), same cache tags as the product; `?reviews=` pages them.
    const reviewCursor = validCursor(query.reviews);
    const reviews = await getReviews(product.slug, locale, reviewCursor);
    const rm = reviewMessages(locale);
    const reviewOutcome = (['sent', 'duplicate', 'invalid', 'failed'] as const).find((o) => o === query.review) ?? null;
    const nextReviews = reviews?.meta.next_cursor ? `/products/${product.slug}?reviews=${encodeURIComponent(reviews.meta.next_cursor)}#reviews` : null;

    const t = getMessages(locale);
    const name = productName(product, locale);
    // P7: the selected variant's flash offer (API `flash_sale.variants`); the variant lives in the URL,
    // so switching variant re-renders this server-side with that variant's own sale price.
    const flash = variantFlash(product.flash_sale, variant.id);
    const basePath = `/products/${product.slug}`;
    const localPath = localizedPath(locale, basePath);
    const explicit = isExplicitVariant(product, query[VARIANT_PARAM]);
    const shellPath = explicit ? variantHref(basePath, variant.id) : basePath;
    const cutoff = rates && beforeCutoff(rates.cutoff_time, new Date()) ? cutoffLabel(rates.cutoff_time, t) : null;
    const glance = ((locale === 'bn' ? (product.short_description_bn ?? product.short_description) : product.short_description) ?? '')
        .split(/\n+/)
        .map((line) => line.replace(/^[-•*]\s*/, '').trim())
        .filter(Boolean);
    const crumbs = [
        { label: t.listing.home, href: localizedPath(locale, '/'), lang: undefined as 'en' | undefined },
        ...product.breadcrumbs.map((c) => ({
            label: locale === 'bn' && c.name_bn ? c.name_bn : c.name,
            href: localizedPath(locale, categoryPath(c.slug)),
            lang: locale === 'bn' && !c.name_bn ? ('en' as const) : undefined,
        })),
    ];

    return (
        <PageShell locale={locale} path={shellPath} top="crumb" bottomBar>
            <JsonLd data={productJsonLd(product, locale, { home: t.listing.home })} />
            <nav aria-label={t.listing.breadcrumb} className="-mx-gutter-mobile overflow-x-auto overflow-y-hidden px-gutter-mobile md:mx-0 md:px-0">
                <ol className="flex h-6 items-center gap-2 whitespace-nowrap">
                    {crumbs.map((crumb) => (
                        <li key={crumb.href} className="flex items-center gap-2">
                            <a href={crumb.href} lang={crumb.lang} className="t-body-sm text-fg-link underline-offset-2 hover:underline">
                                {crumb.label}
                            </a>
                            <Icon name="caretRight" className="size-3.5 text-fg-tertiary" />
                        </li>
                    ))}
                    <li>
                        <a href={localPath} aria-current="page" lang={name.lang} className="t-breadcrumb-current text-fg-secondary">
                            {name.text}
                        </a>
                    </li>
                </ol>
            </nav>

            <div className="mt-2 flex flex-col gap-4 lg:mt-12 lg:flex-row lg:items-start lg:gap-8">
                <div className="lg:sticky lg:top-34 lg:w-152 lg:shrink-0">
                    <ProductGallery product={product} name={name.text} locale={locale} t={t} discountPercent={flash?.discountPercent ?? variant.discount_percent} />
                </div>
                <div className="flex min-w-0 flex-col gap-4 pt-2 lg:grow lg:gap-5 lg:pt-0">
                    <ProductTitle product={product} name={name.text} nameLang={name.lang} locale={locale} />
                    <RatingRow product={product} t={t} />
                    <PriceBlock variant={variant} t={t} flash={flash} />
                    {flash ? <FlashSaleStrip flash={flash} t={t} /> : null}
                    <StockLine variant={variant} t={t} cutoff={cutoff} />
                    <AgeBlock product={product} t={t} />
                    <VariantSelector product={product} selected={variant} locale={locale} t={t} path={localPath} />
                    <PurchasePanel
                        productId={product.id}
                        productPath={`/products/${product.slug}`}
                        variant={variant}
                        name={name.text}
                        t={t}
                        locale={locale}
                        back={variantHref(basePath, variant.id)}
                        estimator={<DeliveryEstimator locale={locale} variantId={variant.id} quantity={1} />}
                        trust={<TrustStrip t={t} />}
                    />
                </div>
            </div>

            {glance.length > 0 ? (
                <section aria-labelledby="pdp-glance" className="mt-12 flex flex-col gap-3 lg:hidden">
                    <h2 id="pdp-glance" className="t-h3 text-fg-primary">
                        {t.pdp.atAGlance}
                    </h2>
                    <ul className="flex flex-col gap-3">
                        {glance.map((line) => (
                            <li key={line} className="t-body flex items-center gap-2.5 text-fg-primary">
                                <Icon name="checkCircle" className="size-4.5 shrink-0 text-status-in-stock" />
                                {line}
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}

            <div className="mt-8 lg:mt-12">
                <ProductInfoPanel
                    product={product}
                    variant={variant}
                    locale={locale}
                    t={t}
                    rates={rates}
                    reviewsTab={reviews ? (product.rating_count > 0 ? `${rm.tab} ${product.rating_count}` : rm.tab) : undefined}
                />
            </div>

            {reviews ? (
                <div className="mt-12">
                    <ProductReviews page={reviews} productId={product.id} slug={product.slug} locale={locale} m={rm} moreHref={nextReviews} outcome={reviewOutcome satisfies ReviewOutcome | null} />
                </div>
            ) : null}

            <div className="mt-12">
                <RelatedProducts products={product.related} locale={locale} t={t} />
            </div>

            {/* Spaces itself: absent (no gap) until it has cards. */}
            <div className="mt-12 empty:hidden">
                <RecentlyViewed slug={product.slug} locale={locale} title={t.pdp.recentlyViewed} t={t.listing} />
            </div>

            <AddToCartBar
                price={formatPrice(flash ? flash.salePrice : variant.price)}
                summary={variantSummary(product, variant, locale) || name.text}
                soldOut={variant.stock_state === 'out_of_stock'}
                labels={{ region: t.pdp.orderBar, addToCart: t.pdp.addToCart, notifyMe: t.pdp.notifyMe, wishlist: format(t.pdp.wishlist, { name: name.text }) }}
                wishlist={{ productId: product.id, locale, back: `/products/${product.slug}` }}
            />
        </PageShell>
    );
}
