'use client';

import type { Locale } from '@/lib/i18n/config';
import { rememberLocale } from '@/lib/locale-cookie';

interface Props {
    /** The locale this link switches TO. */
    target: Locale;
    href: string;
    /** The target language's own name, e.g. "বাংলা" on an English page. */
    label: string;
    accessibleLabel: string;
}

// A plain link to the same page in the other locale, so it works without JavaScript and crawlers
// can follow it. JavaScript only adds: remember the choice in a cookie.
export function LocaleToggle({ target, href, label, accessibleLabel }: Props) {
    return (
        <a
            href={href}
            hrefLang={target}
            lang={target}
            aria-label={`${accessibleLabel}: ${label}`}
            onClick={() => rememberLocale(target)}
            className="t-button inline-flex min-h-touch-min min-w-touch-min items-center justify-center rounded-full border border-line-strong bg-surface px-4 text-fg-primary transition-colors duration-(--tm-duration-fast) ease-standard hover:bg-subtle"
        >
            {label}
        </a>
    );
}
