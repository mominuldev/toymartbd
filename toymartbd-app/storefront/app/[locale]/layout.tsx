import type { Metadata, Viewport } from 'next';
import { notFound } from 'next/navigation';
import type { ReactNode } from 'react';

import { TrackingRoot } from '@/components/tracking/TrackingRoot';
import { WishlistHearts } from '@/components/wishlist/WishlistHearts';
import { fontVariables } from '@/lib/fonts';
import { getMessages, htmlLang, isLocale, localizedPath } from '@/lib/i18n';
import { siteUrl } from '@/lib/seo';
import { getTitleSeparator } from '@/lib/seo/fetch';
import { titleTemplate } from '@/lib/seo/metadata';
import { getTrackingConfig } from '@/lib/tracking/fetch';
import '../globals.css';

// Root layout. Every public page lives under app/[locale]; proxy.ts maps `/x` → `/en/x` (rewrite,
// not redirect) and serves `/bn/x` as-is.

// No params at build time: pages render on first request and are then cached (ISR), so the build
// does not need a reachable API. An unknown locale is a 404 below.
export function generateStaticParams(): Array<{ locale: string }> {
    return [];
}

interface LayoutProps {
    children: ReactNode;
    params: Promise<{ locale: string }>;
}

export async function generateMetadata({ params }: Omit<LayoutProps, 'children'>): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    const t = getMessages(locale);
    return {
        metadataBase: siteUrl(),
        // One separator for every title (config/seo.php via the API) — the same one the server's SEO
        // documents end with, so a page title never carries two different suffixes.
        title: { default: t.site.name, template: titleTemplate(t.site.name, await getTitleSeparator()) },
        description: t.site.tagline,
    };
}

export const viewport: Viewport = {
    width: 'device-width',
    initialScale: 1,
};

export default async function RootLayout({ children, params }: LayoutProps) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();

    const t = getMessages(locale);
    // Public ids + consent mode (tag `tracking-config`); never throws — an API failure means "off".
    const tracking = await getTrackingConfig();

    return (
        <html lang={htmlLang[locale]} data-theme="light" className={fontVariables}>
            <body className={`flex min-h-dvh flex-col antialiased${locale === 'bn' ? ' lang-bn' : ''}`}>
                {/* Consent banner + consent-gated tags (K-01/K-02). First in the DOM so the banner is
                    first in the tab order; it is a fixed overlay, so it moves no content. */}
                <TrackingRoot config={tracking} labels={t.consent} privacyHref={localizedPath(locale, '/pages/privacy')} bangla={locale === 'bn'} />
                {children}
                {/* Every wishlist heart on the page (cards, PDP): filled state + in-place toggle. */}
                <WishlistHearts locale={locale} labels={t.wishlist} />
            </body>
        </html>
    );
}
