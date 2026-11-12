'use client';

import { useEffect, useState } from 'react';

import { ProductRail } from '@/components/listing/ProductRail';
import type { Locale, Messages } from '@/lib/i18n';
import { isCard, type ProductCard } from '@/lib/listing/response';
import { MAX_SHOWN, readSlugs, remember, writeSlugs } from '@/lib/recently-viewed';

// "You looked at these" (P4-STO-07) on the product page, below "Similar toys" — the same rail as
// related products (Figma 128:1556 / 135:1997 geometry). A client island and a progressive
// extra: it remembers this product's slug, then asks GET /api/recently-viewed for the others'
// cards. Nothing renders until there is at least one card (the section is removed when empty,
// content spec C), so it never shifts the page above it and is simply absent without JavaScript.

function storage(): Storage | null {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}

export function RecentlyViewed({ slug, locale, title, t }: { slug: string; locale: Locale; title: string; t: Messages['listing'] }) {
    const [cards, setCards] = useState<ProductCard[]>([]);

    useEffect(() => {
        const store = storage();
        const seen = readSlugs(store);
        writeSlugs(store, remember(seen, slug));
        const others = seen.filter((s) => s !== slug).slice(0, MAX_SHOWN);
        if (others.length === 0) return;

        const controller = new AbortController();
        fetch(`/api/recently-viewed?${new URLSearchParams({ slugs: others.join(','), locale }).toString()}`, { signal: controller.signal })
            .then((res) => (res.ok ? res.json() : null))
            .then((body: unknown) => {
                const data = (body as { data?: unknown } | null)?.data;
                if (Array.isArray(data)) setCards(data.filter(isCard));
            })
            .catch(() => undefined);
        return () => controller.abort();
    }, [slug, locale]);

    if (cards.length === 0) return null;
    return <ProductRail id="pdp-recently-viewed" title={title} products={cards} locale={locale} t={t} />;
}
