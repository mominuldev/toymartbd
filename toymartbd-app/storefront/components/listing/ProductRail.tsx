import type { Locale, Messages } from '@/lib/i18n';
import type { ProductCard as Card } from '@/lib/listing/response';
import { ProductCard } from './ProductCard';

// A product row: a swipe rail of 148 cards bleeding to the screen edge below 1024 (Figma Home
// 127:333 / PDP 128:1556) and a 4-up grid of 302 cards from 1024 (Home 134:448, PDP 135:1997,
// Search "Popular right now" 161:16310). Reuses the listing ProductCard. An empty row renders
// nothing — a section is removed, never shown empty.
// The rail is `relative`: the cards' sr-only text is absolutely positioned, and without a
// containing block inside the scroller it escapes the clip and widens the whole page on mobile.

export function SectionHeader({ id, title, action }: { id: string; title: string; action?: { href: string; label: string; ariaLabel?: string } }) {
    if (!action) {
        return (
            <h2 id={id} className="t-h2 text-fg-primary">
                {title}
            </h2>
        );
    }
    return (
        <div className="flex items-center justify-between gap-4">
            <h2 id={id} className="t-h2 text-fg-primary">
                {title}
            </h2>
            {action ? (
                <a href={action.href} aria-label={action.ariaLabel} className="t-body-sm-strong shrink-0 whitespace-nowrap text-fg-brand underline-offset-4 hover:underline">
                    {action.label}
                </a>
            ) : null}
        </div>
    );
}

interface Props {
    id: string;
    title: string;
    products: Card[];
    locale: Locale;
    t: Messages['listing'];
    action?: { href: string; label: string; ariaLabel?: string };
    /** Desktop columns shown (the rest are mobile-rail only). */
    desktop?: number;
}

export function ProductRail({ id, title, products, locale, t, action, desktop = 4 }: Props) {
    if (products.length === 0) return null;
    return (
        <section aria-labelledby={id} className="flex flex-col gap-3 lg:gap-6">
            <SectionHeader id={id} title={title} action={action} />
            <ul className="relative -mx-gutter-mobile flex snap-x snap-mandatory scroll-px-gutter-mobile gap-3 overflow-x-auto px-gutter-mobile pt-2 pb-6 md:-mx-gutter-tablet md:scroll-px-gutter-tablet md:px-gutter-tablet lg:mx-0 lg:grid lg:grid-cols-4 lg:gap-6 lg:overflow-visible lg:p-0">
                {products.map((product, i) => (
                    <li key={product.id} className={`w-37 shrink-0 snap-start lg:w-auto ${i >= desktop ? 'lg:hidden' : ''}`}>
                        <ProductCard product={product} locale={locale} t={t} />
                    </li>
                ))}
            </ul>
        </section>
    );
}
