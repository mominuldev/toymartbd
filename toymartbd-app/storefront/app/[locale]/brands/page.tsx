import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { PageShell } from '@/components/PageShell';
import { brandName, brandNameIsFallback, listBrands } from '@/lib/brands';
import { getMessages, isLocale, localizedPath } from '@/lib/i18n';
import { alternatesFor } from '@/lib/seo';

// ISR: the API fetch is tagged `brands` with a 3600 s ceiling (lib/api.ts `cached`);
// POST /api/revalidate {"tags":["brands"]} refreshes it immediately.

interface Props {
    params: Promise<{ locale: string }>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    const t = getMessages(locale);
    return {
        title: t.brands.title,
        description: t.brands.metaDescription,
        alternates: alternatesFor(locale, '/brands'),
    };
}

export default async function BrandsPage({ params }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const t = getMessages(locale);
    const brands = await listBrands(locale);

    return (
        <PageShell locale={locale} path="/brands">
            <h1 className="t-h1 text-fg-primary">{t.brands.title}</h1>
            {brands.length === 0 ? (
                <p className="t-body mt-6 text-fg-secondary">{t.brands.empty}</p>
            ) : (
                <ul className="mt-8 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
                    {brands.map((brand) => (
                        <li key={brand.slug}>
                            <a
                                href={localizedPath(locale, `/brands/${brand.slug}`)}
                                className="flex min-h-touch-min h-full items-center rounded-md border border-line-default bg-surface p-4 shadow-e1 transition-shadow duration-(--tm-duration-base) ease-standard hover:shadow-e2"
                            >
                                <h2 className="t-h3 text-fg-primary" lang={brandNameIsFallback(brand, locale) ? 'en' : undefined}>
                                    {brandName(brand, locale)}
                                </h2>
                            </a>
                        </li>
                    ))}
                </ul>
            )}
        </PageShell>
    );
}
