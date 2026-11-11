import type { ReactNode } from 'react';

import { getMessages, localizedPath, otherLocale, type Locale } from '@/lib/i18n';
import { LocaleSuggestion } from './LocaleSuggestion';
import { LocaleToggle } from './LocaleToggle';

interface Props {
    locale: Locale;
    /** Locale-independent path of this page (`/brands`), used for the language toggle. */
    path: string;
    children: ReactNode;
}

const linkClass =
    't-label inline-flex min-h-touch-min items-center rounded-sm px-3 text-fg-primary underline-offset-4 hover:underline';

export function PageShell({ locale, path, children }: Props) {
    const t = getMessages(locale);
    const target = otherLocale(locale);
    // The suggestion is shown in Bangla, so its copy comes from the Bangla catalog.
    const bn = getMessages('bn');

    return (
        <>
            <a
                href="#main"
                className="t-label sr-only rounded-sm bg-surface px-4 py-3 text-fg-primary focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50"
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
            <header className="border-b border-line-default bg-surface">
                <div className="mx-auto flex max-w-content flex-wrap items-center justify-between gap-3 px-gutter-mobile py-2 md:px-gutter-tablet lg:px-gutter-desktop">
                    <a
                        href={localizedPath(locale, '/')}
                        className="t-h4 inline-flex min-h-touch-min items-center rounded-sm text-fg-brand"
                    >
                        {t.site.name}
                    </a>
                    <nav aria-label={t.nav.primary} className="flex items-center gap-2">
                        <a href={localizedPath(locale, '/brands')} className={linkClass}>
                            {t.nav.brands}
                        </a>
                        <LocaleToggle
                            target={target}
                            href={localizedPath(target, path)}
                            label={t.language[target]}
                            accessibleLabel={t.language.switchLabel}
                        />
                    </nav>
                </div>
            </header>
            <main id="main" className="mx-auto w-full max-w-content grow px-gutter-mobile py-section-mobile md:px-gutter-tablet lg:px-gutter-desktop lg:py-section-desktop">
                {children}
            </main>
            <footer className="border-t border-line-default bg-surface">
                <p className="t-caption mx-auto max-w-content px-gutter-mobile py-6 text-fg-secondary md:px-gutter-tablet lg:px-gutter-desktop">
                    {t.footer.copyright}
                </p>
            </footer>
        </>
    );
}
