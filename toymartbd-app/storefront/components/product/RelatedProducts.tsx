import { ProductRail } from '@/components/listing/ProductRail';
import type { Locale, Messages } from '@/lib/i18n';
import type { StorefrontProduct } from '@/lib/product/variants';

// "Similar toys" (P4-STO-07) — Figma 135:1997 (1440: a 4-up grid of 302 cards) and 128:1556 (390:
// a swipe rail of 148 cards bleeding to the screen edge). The API has already dropped
// out-of-stock, unpublished and self targets (rule 25c); an empty row hides.
export function RelatedProducts({ products, locale, t }: { products: StorefrontProduct['related']; locale: Locale; t: Messages }) {
    return <ProductRail id="pdp-related" title={t.pdp.similar} products={products} locale={locale} t={t.listing} />;
}
