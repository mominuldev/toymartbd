import { Icon } from '@/components/icons/Icon';
import { format, type Messages } from '@/lib/i18n';
import type { ListingState } from '@/lib/listing/state';
import { pageHref, pageWindow } from '@/lib/search/query';

// Pagination — Figma 76:820, as placed on Search results 161:15317: the status line, then
// Numbered (Prev · pages · Next, 44 circles) from 480px up and Compact (Prev · "Page 2 of 5" ·
// Next, space-between) below. Every page is a real <a href="…&page=n"> with rel=prev/next, so it
// works without JavaScript and is crawlable. Prev/Next at the ends are aria-disabled, focusable,
// with the icon in text/tertiary. Numbers are Western and tabular in both locales (rule 36).

interface Props {
    /** Localised `/search?q=…`. */
    base: string;
    state: ListingState;
    page: number;
    lastPage: number;
    from: number | null;
    to: number | null;
    total: number;
    t: Messages['search'];
}

const circle = 'relative flex size-touch-min shrink-0 items-center justify-center rounded-full';

function Step({ href, label, icon, rel }: { href: string | null; label: string; icon: 'caretLeft' | 'caretRight'; rel: 'prev' | 'next' }) {
    if (href === null) {
        return (
            <a role="link" aria-disabled="true" aria-label={label} tabIndex={0} className={`${circle} text-fg-tertiary`}>
                <Icon name={icon} className="size-5" />
            </a>
        );
    }
    return (
        <a href={href} rel={rel} aria-label={label} className={`${circle} text-fg-primary hover:bg-fg-primary/8`}>
            <Icon name={icon} className="size-5" />
        </a>
    );
}

export function Pagination({ base, state, page, lastPage, from, to, total, t }: Props) {
    const status = from !== null && to !== null ? format(t.showingRange, { from: String(from), to: String(to), total: String(total) }) : null;
    const prev = page > 1 ? pageHref(base, state, page - 1) : null;
    const next = page < lastPage ? pageHref(base, state, page + 1) : null;

    return (
        <div className="flex flex-col items-center gap-3 pt-8 pb-12">
            {status ? (
                <p className="t-body-sm text-center text-fg-secondary" aria-live="polite">
                    {status}
                </p>
            ) : null}
            {lastPage > 1 ? (
                <nav aria-label={t.pagination} className="w-full sm:w-auto">
                    {/* Compact, below sm */}
                    <div className="flex items-center justify-between gap-3 sm:hidden">
                        <Step href={prev} label={t.previous} icon="caretLeft" rel="prev" />
                        <p className="t-body-sm-strong min-w-28 text-center tabular-nums text-fg-primary">{format(t.pageOf, { page: String(page), total: String(lastPage) })}</p>
                        <Step href={next} label={t.next} icon="caretRight" rel="next" />
                    </div>
                    {/* Numbered, from sm (the 480 rule rounded to the nearest breakpoint) */}
                    <div className="hidden items-center justify-center gap-3 sm:flex">
                        <Step href={prev} label={t.previous} icon="caretLeft" rel="prev" />
                        <ol className="flex items-center gap-2">
                            {pageWindow(page, lastPage).map((n, i) =>
                                n === 'gap' ? (
                                    <li key={`gap-${i}`} aria-hidden="true" className={`${circle} t-pagination-page text-fg-tertiary`}>
                                        …
                                    </li>
                                ) : (
                                    <li key={n}>
                                        {n === page ? (
                                            <a
                                                href={pageHref(base, state, n)}
                                                aria-current="page"
                                                aria-label={format(t.pageCurrent, { page: String(n) })}
                                                className={`${circle} t-pagination-page-current bg-brand tabular-nums text-fg-on-brand`}
                                            >
                                                {n}
                                            </a>
                                        ) : (
                                            <a
                                                href={pageHref(base, state, n)}
                                                aria-label={format(t.page, { page: String(n) })}
                                                className={`${circle} t-pagination-page tabular-nums text-fg-primary hover:bg-fg-primary/8`}
                                            >
                                                {n}
                                            </a>
                                        )}
                                    </li>
                                ),
                            )}
                        </ol>
                        <Step href={next} label={t.next} icon="caretRight" rel="next" />
                    </div>
                </nav>
            ) : null}
        </div>
    );
}
