import { TileLink } from '@/components/CategoryTile';
import { ageIcon } from '@/components/listing/ProductCard';
import { SectionHeader } from '@/components/listing/ProductRail';
import { format, localizedPath, type Locale, type Messages } from '@/lib/i18n';
import { AGE_BANDS } from '@/lib/listing/state';

// "Shop by age" (Figma Home): seven age tiles with the live count of listable toys per band.
// Keeps the `shop-by-age` anchor the hero's "See all" and the footer link to.

export function AgeTilesBlock({ id, title, showSeeAll, counts, locale, t }: { id: string; title: string; showSeeAll: boolean; counts: Record<string, number>; locale: Locale; t: Messages['home'] }) {
    const headingId = `cms-${id}-title`;
    const labels = t.ageTiles as Record<string, string>;
    const toys = (count: number | undefined) => (count === undefined ? undefined : count === 1 ? t.toyCountOne : format(t.toyCount, { count: String(count) }));

    return (
        <section id="shop-by-age" aria-labelledby={headingId} className="flex scroll-mt-34 flex-col gap-3 lg:gap-6">
            <SectionHeader id={headingId} title={title} action={showSeeAll ? { href: localizedPath(locale, '/toys'), label: t.seeAll, ariaLabel: format(t.seeAllLabel, { section: title }) } : undefined} />
            <ul className="relative -mx-gutter-mobile flex gap-3 overflow-x-auto px-gutter-mobile pt-2 pb-6 md:-mx-gutter-tablet md:px-gutter-tablet lg:mx-0 lg:grid lg:grid-cols-7 lg:gap-3 lg:overflow-visible lg:p-0">
                {AGE_BANDS.map((band, i) => (
                    <li key={band} className="w-37 shrink-0 lg:w-auto">
                        <TileLink href={localizedPath(locale, `/toys?age=${band}`)} icon={ageIcon(Number.parseInt(band, 10))} label={labels[band] ?? band} count={toys(counts[band])} index={i} />
                    </li>
                ))}
            </ul>
        </section>
    );
}
