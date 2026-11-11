import type { ReactNode } from 'react';

import { buildMenu } from '@/lib/category-tree';
import { getCategoryTree } from '@/lib/categories';
import { format, getMessages, localizedPath, type Locale } from '@/lib/i18n';
import { LocaleSuggestion } from './LocaleSuggestion';
import { MobileNav } from './layout/MobileNav';
import { SiteFooter } from './layout/SiteFooter';
import { SiteHeader } from './layout/SiteHeader';

interface Props {
    locale: Locale;
    /**
     * Locale-independent path of this page (`/brands`, `/search?q=lego`). The language toggle links
     * to the same path in the other locale, and the mobile tab bar highlights from it. It is a prop
     * rather than read from the request so that pages stay ISR-cacheable.
     */
    path: string;
    /** Pre-fills the header search bar (search page). */
    searchQuery?: string;
    children: ReactNode;
}

// The storefront chrome: skip link → (Bangla suggestion) → header + mega menu → main → footer →
// mobile tab bar. Server-rendered; the only client islands are the mega menu's keyboard layer,
// the language toggle's cookie and the suggestion banner.
export async function PageShell({ locale, path, searchQuery, children }: Props) {
    const t = getMessages(locale);
    // The suggestion is shown in Bangla, so its copy comes from the Bangla catalog.
    const bn = getMessages('bn');
    const tree = await getCategoryTree(); // never throws: an API failure renders no menu
    const menu = buildMenu(tree, locale, {
        viewAll: (category) => format(t.nav.viewAll, { category }),
        more: t.nav.more,
        allCategories: t.nav.allCategories,
    });

    return (
        <>
            <a
                href="#main"
                className="t-label sr-only rounded-sm bg-surface px-4 py-3 text-fg-primary shadow-e2 focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-50"
            >
                {t.nav.skipToContent}
            </a>
            {locale === 'en' ? (
                <LocaleSuggestion
                    href={localizedPath('bn', path)}
                    message={bn.suggest.message}
                    accept={bn.suggest.accept}
                    dismiss={bn.suggest.dismiss}
                    region={bn.suggest.region}
                />
            ) : null}
            <SiteHeader locale={locale} path={path} menu={menu} searchQuery={searchQuery} />
            <main
                id="main"
                tabIndex={-1}
                className="mx-auto w-full max-w-content grow px-gutter-mobile pt-section-mobile focus:outline-none md:px-gutter-tablet lg:px-gutter-desktop lg:pt-section-desktop"
            >
                {children}
            </main>
            <SiteFooter locale={locale} path={path} />
            <MobileNav locale={locale} path={path} />
        </>
    );
}
