import { blockLink } from '@/components/content/blocks/shared';
import { ProductRail } from '@/components/listing/ProductRail';
import { format, type Locale, type Messages } from '@/lib/i18n';
import type { ProductCard } from '@/lib/listing/response';

// A product rail by rule (Phase 7) — the listing ProductRail (Home 127:333 / 134:448). The API
// already applied the guardrails (rule 25c): only listable products, sold-out ones dropped when the
// editor chose so, and a rail below its minimum never arrives. Homepage only — never on checkout
// (rule 25b).

export function ProductRailBlock({ id, title, products, seeAllHref, locale, t }: { id: string; title: string; products: ProductCard[]; seeAllHref: string | null; locale: Locale; t: Messages }) {
    const link = seeAllHref ? blockLink(locale, seeAllHref) : null;
    return (
        <ProductRail
            id={`cms-${id}-title`}
            title={title}
            products={products}
            locale={locale}
            t={t.listing}
            action={link ? { href: link.href, label: t.home.seeAll, ariaLabel: format(t.home.seeAllLabel, { section: title }) } : undefined}
        />
    );
}
