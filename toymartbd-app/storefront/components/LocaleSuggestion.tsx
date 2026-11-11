'use client';

import { useEffect, useState } from 'react';

import { rememberLocale, rememberedLocale } from '@/lib/locale-cookie';

interface Props {
    href: string;
    message: string;
    accept: string;
    dismiss: string;
    region: string;
}

// Rendered on English pages only. Bangla is SUGGESTED, never forced (UI-UX spec §6.6): the page
// HTML is identical for every visitor (so ISR/CDN caching and crawlers are unaffected), and this
// banner appears client-side when the browser's language list — the source of its
// Accept-Language header — prefers Bangla and the visitor has not chosen a language yet, or when
// they previously chose Bangla. Dismissing stores "en" and the banner never returns.
export function LocaleSuggestion({ href, message, accept, dismiss, region }: Props) {
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        const chosen = rememberedLocale();
        if (chosen === 'en') return;
        const prefersBangla =
            chosen === 'bn' || (navigator.languages ?? [navigator.language]).some((l) => l.toLowerCase().startsWith('bn'));
        if (prefersBangla) setVisible(true);
    }, []);

    if (!visible) return null;

    return (
        <section aria-label={region} lang="bn" className="border-b border-line-default bg-brand-subtle">
            <div className="mx-auto flex max-w-content flex-wrap items-center gap-3 px-gutter-mobile py-2 md:px-gutter-tablet lg:px-gutter-desktop">
                <p className="t-body-sm grow text-fg-primary">{message}</p>
                <a
                    href={href}
                    hrefLang="bn"
                    onClick={() => rememberLocale('bn')}
                    className="t-button inline-flex min-h-touch-min items-center rounded-full bg-brand px-5 text-fg-on-brand"
                >
                    {accept}
                </a>
                <button
                    type="button"
                    onClick={() => {
                        rememberLocale('en');
                        setVisible(false);
                    }}
                    className="t-button inline-flex min-h-touch-min items-center rounded-full px-4 text-fg-primary hover:bg-subtle"
                >
                    {dismiss}
                </button>
            </div>
        </section>
    );
}
