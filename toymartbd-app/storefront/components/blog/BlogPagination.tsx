import { Icon } from '@/components/icons/Icon';
import { format, localizedPath, type Locale } from '@/lib/i18n';
import { pagePath } from '@/lib/blog/format';
import type { BlogMessages } from '@/lib/blog/messages';

// Numbered pagination for blog lists — the search Pagination's look (Figma 76:820): Numbered from
// sm, Compact below. Every page is a real <a href> (page 1 = the bare URL) with rel=prev/next, so it
// works without JavaScript and crawlers follow it; the <head> carries matching rel links.

interface Props {
    locale: Locale;
    /** Locale-independent list path (`/blog`, `/blog/category/x`). */
    path: string;
    page: number;
    lastPage: number;
    total: number;
    perPage: number;
    t: BlogMessages;
}

const circle = 'relative flex size-touch-min shrink-0 items-center justify-center rounded-full';

function window(page: number, last: number): Array<number | 'gap'> {
    const out: Array<number | 'gap'> = [];
    for (let n = 1; n <= last; n++) {
        if (n === 1 || n === last || Math.abs(n - page) <= 1) out.push(n);
        else if (out[out.length - 1] !== 'gap') out.push('gap');
    }
    return out;
}

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

export function BlogPagination({ locale, path, page, lastPage, total, perPage, t }: Props) {
    const href = (n: number) => localizedPath(locale, pagePath(path, n));
    const prev = page > 1 ? href(page - 1) : null;
    const next = page < lastPage ? href(page + 1) : null;
    const from = total === 0 ? 0 : (page - 1) * perPage + 1;
    const to = Math.min(total, page * perPage);

    return (
        <div className="flex flex-col items-center gap-3 pt-8 pb-4">
            {prev ? <link rel="prev" href={prev} /> : null}
            {next ? <link rel="next" href={next} /> : null}
            {total > 0 ? <p className="t-body-sm text-center text-fg-secondary">{format(t.showingRange, { from: String(from), to: String(to), total: String(total) })}</p> : null}
            {lastPage > 1 ? (
                <nav aria-label={t.pagination} className="w-full sm:w-auto">
                    <div className="flex items-center justify-between gap-3 sm:hidden">
                        <Step href={prev} label={t.previous} icon="caretLeft" rel="prev" />
                        <p className="t-body-sm-strong min-w-28 text-center tabular-nums text-fg-primary">{format(t.pageOf, { page: String(page), total: String(lastPage) })}</p>
                        <Step href={next} label={t.next} icon="caretRight" rel="next" />
                    </div>
                    <div className="hidden items-center justify-center gap-3 sm:flex">
                        <Step href={prev} label={t.previous} icon="caretLeft" rel="prev" />
                        <ol className="flex items-center gap-2">
                            {window(page, lastPage).map((n, i) =>
                                n === 'gap' ? (
                                    <li key={`gap-${i}`} aria-hidden="true" className={`${circle} t-pagination-page text-fg-tertiary`}>
                                        …
                                    </li>
                                ) : (
                                    <li key={n}>
                                        <a
                                            href={href(n)}
                                            aria-current={n === page ? 'page' : undefined}
                                            aria-label={format(n === page ? t.pageCurrent : t.page, { page: String(n) })}
                                            className={`${circle} tabular-nums ${n === page ? 't-pagination-page-current bg-brand text-fg-on-brand' : 't-pagination-page text-fg-primary hover:bg-fg-primary/8'}`}
                                        >
                                            {n}
                                        </a>
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
