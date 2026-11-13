import { SectionHeader } from '@/components/listing/ProductRail';
import { format, localizedPath, type Locale, type Messages } from '@/lib/i18n';

// Brand strip (Phase 7): logo tiles linking to each brand page; a brand without a logo shows its
// name. Swipe row below 1024, a wrapping row from 1024. No Figma frame of its own — it reuses the
// brands-page tile language (surface, hairline, radius/sm, e1). Logo boxes have a fixed size, so
// nothing shifts when they load.

export function BrandStripBlock({ id, title, brands, locale, t }: { id: string; title: string; brands: Array<{ name: string; slug: string; logo_url: string | null }>; locale: Locale; t: Messages['brands'] }) {
    const headingId = `cms-${id}-title`;
    return (
        <section aria-labelledby={headingId} className="flex flex-col gap-3 lg:gap-6">
            <SectionHeader id={headingId} title={title} />
            <ul className="relative -mx-gutter-mobile flex gap-3 overflow-x-auto px-gutter-mobile pb-2 md:-mx-gutter-tablet md:px-gutter-tablet lg:mx-0 lg:flex-wrap lg:gap-4 lg:overflow-visible lg:p-0">
                {brands.map((brand) => (
                    <li key={brand.slug} className="shrink-0">
                        <a
                            href={localizedPath(locale, `/brands/${brand.slug}`)}
                            className="flex h-20 w-36 items-center justify-center rounded-sm border border-line-default bg-surface p-3 shadow-e1 hover:border-line-strong"
                        >
                            {brand.logo_url ? (
                                <img src={brand.logo_url} alt={format(t.logoAlt, { name: brand.name })} width={120} height={56} loading="lazy" decoding="async" className="h-14 w-30 object-contain" />
                            ) : (
                                <span className="t-body-sm-strong text-center text-fg-primary">{brand.name}</span>
                            )}
                        </a>
                    </li>
                ))}
            </ul>
        </section>
    );
}
