import type { Metadata, Viewport } from 'next';
import { notFound } from 'next/navigation';
import type { ReactNode } from 'react';

import { WishlistHearts } from '@/components/wishlist/WishlistHearts';
import { fontVariables } from '@/lib/fonts';
import { getMessages, htmlLang, isLocale } from '@/lib/i18n';
import { siteUrl } from '@/lib/seo';
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
        title: { default: t.site.name, template: `%s · ${t.site.name}` },
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

    return (
        <html lang={htmlLang[locale]} data-theme="light" className={fontVariables}>
            <body className={`flex min-h-dvh flex-col antialiased${locale === 'bn' ? ' lang-bn' : ''}`}>
                {children}
                {/* Every wishlist heart on the page (cards, PDP): filled state + in-place toggle. */}
                <WishlistHearts locale={locale} labels={t.wishlist} />
            </body>
        </html>
    );
}
