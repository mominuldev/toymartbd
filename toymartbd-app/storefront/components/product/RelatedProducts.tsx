import { ProductCard } from '@/components/listing/ProductCard';
import type { Locale, Messages } from '@/lib/i18n';
import type { StorefrontProduct } from '@/lib/product/variants';

// "Similar toys" (P4-STO-07) — Figma 135:1997 (1440: a 4-up grid of 302 cards) and 128:1556 (390:
// a swipe rail of 148 cards bleeding to the screen edge). Reuses the listing ProductCard. The API
// has already dropped out-of-stock, unpublished and self targets (rule 25c); an empty row hides.
export function RelatedProducts({ products, locale, t }: { products: StorefrontProduct['related']; locale: Locale; t: Messages }) {
    if (products.length === 0) return null;
    return (
        <section aria-labelledby="pdp-related" className="flex flex-col gap-3 lg:gap-6">
            <h2 id="pdp-related" className="t-h2 text-fg-primary">
                {t.pdp.similar}
            </h2>
            <ul className="-mx-gutter-mobile flex snap-x snap-mandatory scroll-px-gutter-mobile gap-3 overflow-x-auto px-gutter-mobile pt-2 pb-6 md:-mx-gutter-tablet md:scroll-px-gutter-tablet md:px-gutter-tablet lg:mx-0 lg:grid lg:grid-cols-4 lg:gap-6 lg:overflow-visible lg:p-0">
                {products.map((product, i) => (
                    <li key={product.id} className={`w-37 shrink-0 snap-start lg:w-auto ${i >= 4 ? 'lg:hidden' : ''}`}>
                        <ProductCard product={product} locale={locale} t={t.listing} />
                    </li>
                ))}
            </ul>
        </section>
    );
}
