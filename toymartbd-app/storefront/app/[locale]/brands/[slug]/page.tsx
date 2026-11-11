import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { cache } from 'react';

import { PageShell } from '@/components/PageShell';
import { brandDescription, brandName, brandNameIsFallback, getBrand } from '@/lib/brands';
import { format, getMessages, isLocale, localizedPath, type Locale } from '@/lib/i18n';
import { alternatesFor } from '@/lib/seo';

// ISR: tagged `brands` + `brand:{slug}`. Slugs are ASCII and shared by both locales (rule 37).
// Empty generateStaticParams: each brand renders on its first visit, then is cached (ISR) —
// the build never needs the API.

interface Props {
    params: Promise<{ locale: string; slug: string }>;
}

export function generateStaticParams(): Array<{ slug: string }> {
    return [];
}

const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

// One API call per request, shared by generateMetadata and the page.
const load = cache(async (slug: string, locale: Locale) => (SLUG.test(slug) ? getBrand(slug, locale) : null));

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale, slug } = await params;
    if (!isLocale(locale)) return {};
    const brand = await load(slug, locale);
    if (!brand) return {};
    return {
        title: brandName(brand, locale),
        description: brandDescription(brand, locale) ?? getMessages(locale).brands.metaDescription,
        alternates: alternatesFor(locale, `/brands/${slug}`),
    };
}

export default async function BrandPage({ params }: Props) {
    const { locale, slug } = await params;
    if (!isLocale(locale)) notFound();
    const brand = await load(slug, locale);
    if (!brand) notFound();

    const t = getMessages(locale);
    const name = brandName(brand, locale);
    const description = brandDescription(brand, locale);

    return (
        <PageShell locale={locale} path={`/brands/${slug}`}>
            <p>
                <a
                    href={localizedPath(locale, '/brands')}
                    className="t-label inline-flex min-h-touch-min items-center text-fg-link underline underline-offset-4"
                >
                    {t.brands.backToAll}
                </a>
            </p>
            <article className="mt-6">
                <h1 className="t-h1 text-fg-primary" lang={brandNameIsFallback(brand, locale) ? 'en' : undefined}>
                    {name}
                </h1>
                {brand.logo_url ? (
                    // Plain <img>: the logo host is not configured for next/image yet (images.remotePatterns).
                    <img
                        src={brand.logo_url}
                        alt={format(t.brands.logoAlt, { name })}
                        width={160}
                        height={160}
                        loading="lazy"
                        className="mt-6 size-40 rounded-md bg-surface object-contain"
                    />
                ) : null}
                {description ? <p className="t-body-lg mt-6 max-w-prose text-fg-secondary">{description}</p> : null}
            </article>
        </PageShell>
    );
}
