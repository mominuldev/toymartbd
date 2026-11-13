import type { Metadata } from 'next';
import { headers } from 'next/headers';

import { PageShell } from '@/components/PageShell';
import { NotFoundContent } from '@/components/status/NotFoundContent';
import { fontVariables } from '@/lib/fonts';
import { getMessages, htmlLang } from '@/lib/i18n';
import { LOCALE_HEADER, PATH_HEADER, requestContext } from '@/lib/locale-route';
import { siteUrl } from '@/lib/seo';
import { getTitleSeparator } from '@/lib/seo/fetch';
import './globals.css';

// The 404 for every URL that matches no route, in both locales (`/no-such-page`,
// `/bn/no-such-page`). Enabled by `experimental.globalNotFound` in next.config.ts.
//
// Why this exists: the root layout is the dynamic segment app/[locale], so an unmatched URL had
// no localised not-found at all (Next's unstyled default), and a route-level notFound() in
// Next 16.4.3 sends an empty HTML shell that only JavaScript fills in. This file is rendered
// server-side as a complete document, so the styled page — header, footer, search, a way back
// into the catalog — works with JavaScript disabled, with HTTP 404 and noindex.
//
// It bypasses app/[locale]/layout.tsx, so it repeats what that layout does (fonts, tokens,
// `lang`, `.lang-bn`). It has no params; proxy.ts tells it the locale and path via request
// headers (lib/locale-route.ts `requestContext`).

async function context() {
    const h = await headers();
    return requestContext(h.get(LOCALE_HEADER), h.get(PATH_HEADER));
}

export async function generateMetadata(): Promise<Metadata> {
    const { locale } = await context();
    const t = getMessages(locale);
    return { metadataBase: siteUrl(), title: `${t.notFound.title}${await getTitleSeparator()}${t.site.name}` };
}

export default async function GlobalNotFound() {
    const { locale, path } = await context();
    return (
        <html lang={htmlLang[locale]} data-theme="light" className={fontVariables}>
            <body className={`flex min-h-dvh flex-col antialiased${locale === 'bn' ? ' lang-bn' : ''}`}>
                <PageShell locale={locale} path={path} top="tight">
                    <NotFoundContent locale={locale} />
                </PageShell>
            </body>
        </html>
    );
}
