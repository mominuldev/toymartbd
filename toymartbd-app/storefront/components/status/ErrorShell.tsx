'use client';

import { usePathname } from 'next/navigation';

import { MobileNav } from '@/components/layout/MobileNav';
import { SiteFooter } from '@/components/layout/SiteFooter';
import { SiteHeader } from '@/components/layout/SiteHeader';
import { splitLocalePath, type Locale } from '@/lib/i18n/config';
import { ServerErrorContent } from './ServerErrorContent';

// The 500 page with the site chrome around it, as Figma 169:10286 / 173:19350 draws it. It runs
// on the client (error boundaries are client components), so the chrome is the static part only:
// the mega-menu bar needs the category tree from the server and is left out here.
export function ErrorShell({ locale, onRetry }: { locale: Locale; onRetry?: () => void }) {
    const pathname = usePathname() ?? '/';
    const { path } = splitLocalePath(pathname);
    return (
        <>
            <SiteHeader locale={locale} path={path} menu={[]} />
            <main id="main" tabIndex={-1} className="w-full grow px-gutter-mobile pt-6 focus:outline-none md:px-gutter-tablet lg:px-gutter-desktop lg:pt-8">
                <div className="mx-auto max-w-content">
                    <ServerErrorContent locale={locale} onRetry={onRetry} />
                </div>
            </main>
            <SiteFooter locale={locale} path={path} />
            <MobileNav locale={locale} path={path} />
        </>
    );
}
