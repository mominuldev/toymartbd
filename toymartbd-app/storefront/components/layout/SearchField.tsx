'use client';

import { useCallback, useEffect, useId, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';

import { categoryIcon } from '@/components/CategoryTile';
import { Icon } from '@/components/icons/Icon';
import { format, localizedPath, type Locale, type Messages } from '@/lib/i18n';
import { ageLabel, formatPrice, type AgeCopy } from '@/lib/listing/format';
import { MIN_SUGGEST_QUERY, parseQuery, searchPath } from '@/lib/search/query';
import { normalizeSuggestions, type Suggestions } from '@/lib/search/response';

// The search input with its suggestion dropdown — Figma SearchBar 111:853 (HasQuery: the clear
// ×) and SearchSuggestions 115:887 (storefront spec §A6.2). Progressive enhancement: the server
// renders a plain input inside the GET form, so search works without JavaScript; once hydrated
// this adds the dropdown. 250 ms debounce, 2-character minimum, products → categories → brands,
// then "See all N results". ↑/↓ walk every row across groups (DOM focus stays in the input,
// aria-activedescendant), Enter opens the active row or submits, Esc closes keeping the query,
// a second Esc clears. With an empty query, focus shows recent searches (this browser only).
// Suggestions come through GET /api/search-suggest (rule 28); prices are shown as sent (rule 1).

const DEBOUNCE_MS = 250;
const RECENT_KEY = 'tm:recent-searches';
const MAX_RECENT = 6;

type Copy = Pick<
    Messages['search'],
    'label' | 'placeholder' | 'clear' | 'suggestProducts' | 'suggestCategories' | 'suggestBrands' | 'suggestSeeAll' | 'suggestNoMatch' | 'suggestCount' | 'recentTitle' | 'recentClear' | 'recentRemove'
>;

interface Props {
    locale: Locale;
    id: string;
    defaultValue?: string;
    copy: Copy;
    /** Age-label templates for the product rows (shared with the product card). */
    ageCopy: AgeCopy;
}

interface Row {
    key: string;
    href: string;
}

function readRecent(): string[] {
    try {
        const raw: unknown = JSON.parse(window.localStorage.getItem(RECENT_KEY) ?? '[]');
        return Array.isArray(raw) ? raw.filter((v): v is string => typeof v === 'string' && v.length > 0).slice(0, MAX_RECENT) : [];
    } catch {
        return [];
    }
}

function writeRecent(list: string[]): void {
    try {
        window.localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, MAX_RECENT)));
    } catch {
        // Storage blocked or full: recent searches are a convenience only.
    }
}

export function SearchField({ locale, id, defaultValue = '', copy, ageCopy }: Props) {
    const [value, setValue] = useState(defaultValue);
    const [open, setOpen] = useState(false);
    const [data, setData] = useState<Suggestions | null>(null);
    const [recent, setRecent] = useState<string[]>([]);
    const [active, setActive] = useState(-1);
    const inputRef = useRef<HTMLInputElement>(null);
    const wrapRef = useRef<HTMLDivElement>(null);
    const listId = useId();
    const optionId = (i: number) => `${listId}-o${i}`;
    const query = parseQuery(value);
    const ready = [...query].length >= MIN_SUGGEST_QUERY;

    // Debounced fetch; a newer keystroke aborts the older request.
    useEffect(() => {
        if (!ready) {
            setData(null);
            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(`/api/search-suggest?${new URLSearchParams({ q: query, locale }).toString()}`, { signal: controller.signal })
                .then((res) => (res.ok ? res.json() : null))
                .then((body: unknown) => {
                    if (body !== null) setData(normalizeSuggestions(body));
                })
                .catch(() => undefined);
        }, DEBOUNCE_MS);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query, ready, locale]);

    // Remember submitted searches; close on a click outside.
    useEffect(() => {
        const form = inputRef.current?.form;
        const onSubmit = () => {
            const q = parseQuery(inputRef.current?.value ?? '');
            if (q) writeRecent([q, ...readRecent().filter((r) => r !== q)]);
        };
        const onDown = (e: PointerEvent) => {
            if (!wrapRef.current?.contains(e.target as Node)) setOpen(false);
        };
        form?.addEventListener('submit', onSubmit);
        document.addEventListener('pointerdown', onDown);
        return () => {
            form?.removeEventListener('submit', onSubmit);
            document.removeEventListener('pointerdown', onDown);
        };
    }, []);

    const showRecent = !ready && recent.length > 0;
    const fresh = data !== null && data.query === query ? data : null;
    const results = ready && fresh !== null;
    const noMatch = results && fresh.products.length + fresh.categories.length + fresh.brands.length === 0;

    // Every keyboard-reachable row, in DOM order.
    const rows: Row[] = [];
    if (results && !noMatch) {
        fresh.products.forEach((p) => rows.push({ key: `p:${p.slug}`, href: localizedPath(locale, `/products/${p.slug}`) }));
        fresh.categories.forEach((c) => rows.push({ key: `c:${c.slug}`, href: localizedPath(locale, `/categories/${c.slug}`) }));
        fresh.brands.forEach((b) => rows.push({ key: `b:${b.slug}`, href: localizedPath(locale, `/brands/${b.slug}`) }));
        rows.push({ key: 'all', href: localizedPath(locale, searchPath(query)) });
    } else if (showRecent) {
        recent.forEach((r) => rows.push({ key: `r:${r}`, href: localizedPath(locale, searchPath(r)) }));
    }
    const indexOf = (key: string) => rows.findIndex((r) => r.key === key);
    const expanded = open && (results || showRecent);

    useEffect(() => setActive(-1), [query]);

    const onKeyDown = useCallback(
        (e: KeyboardEvent<HTMLInputElement>) => {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                if (!expanded) {
                    setOpen(true);
                    return;
                }
                e.preventDefault();
                if (rows.length === 0) return;
                const step = e.key === 'ArrowDown' ? 1 : -1;
                // Cycle through -1 (the input itself) and every row.
                setActive((i) => {
                    const next = i + step;
                    return next >= rows.length ? -1 : next < -1 ? rows.length - 1 : next;
                });
            } else if (e.key === 'Enter' && expanded && active >= 0 && rows[active]) {
                e.preventDefault();
                window.location.assign(rows[active].href);
            } else if (e.key === 'Escape') {
                // type=search clears itself on Escape; the first press only closes the list.
                e.preventDefault();
                if (expanded) {
                    setOpen(false);
                    setActive(-1);
                } else if (value) {
                    setValue('');
                }
            }
        },
        [expanded, rows, active, value],
    );

    const rowClass = (key: string) => `flex items-center gap-3 px-4 ${indexOf(key) === active ? 'bg-brand-subtle' : 'hover:bg-subtle'}`;
    const header = (title: string, count?: number) => (
        <li role="presentation" className="flex h-7 items-center justify-between px-4 text-fg-tertiary">
            <span className="t-overline">{title}</span>
            {count !== undefined ? <span className="t-caption tabular-nums">{count}</span> : null}
        </li>
    );
    const divider = <li role="presentation" aria-hidden="true" className="h-px bg-line-default" />;
    const option = (key: string, href: string, className: string, children: ReactNode, label?: string) => {
        const i = indexOf(key);
        return (
            <li key={key} id={optionId(i)} role="option" aria-selected={i === active} aria-label={label} className="list-none">
                <a href={href} tabIndex={-1} className={`${rowClass(key)} ${className}`}>
                    {children}
                </a>
            </li>
        );
    };

    return (
        <div ref={wrapRef} className="relative flex h-full min-w-0 flex-1 items-center">
            <input
                ref={inputRef}
                id={id}
                name="q"
                type="search"
                value={value}
                onChange={(e) => {
                    setValue(e.target.value);
                    setOpen(true);
                }}
                onFocus={() => {
                    setRecent(readRecent());
                    setOpen(true);
                }}
                onKeyDown={onKeyDown}
                placeholder={copy.placeholder}
                autoComplete="off"
                enterKeyHint="search"
                role="combobox"
                aria-autocomplete="list"
                aria-expanded={expanded}
                aria-controls={listId}
                aria-activedescendant={expanded && active >= 0 ? optionId(active) : undefined}
                className="t-body h-full min-w-0 flex-1 bg-transparent text-ellipsis text-fg-primary placeholder:text-fg-tertiary focus:outline-none focus-visible:shadow-none [&::-webkit-search-cancel-button]:hidden"
            />
            {value ? (
                <button
                    type="button"
                    aria-label={copy.clear}
                    onClick={() => {
                        setValue('');
                        setData(null);
                        inputRef.current?.focus();
                    }}
                    className="relative flex size-8 shrink-0 items-center justify-center rounded-full text-fg-secondary hover:bg-subtle before:absolute before:size-touch-min before:content-['']"
                >
                    <Icon name="x" className="size-icon-sm" />
                </button>
            ) : null}
            <p className="sr-only" aria-live="polite">
                {results && !noMatch ? format(copy.suggestCount, { count: String(rows.length - 1) }) : ''}
            </p>
            <ul
                id={listId}
                role="listbox"
                aria-label={copy.label}
                hidden={!expanded}
                className="absolute top-full -right-1 -left-12 z-40 mt-2 max-h-130 overflow-y-auto rounded-md border border-line-brand-subtle bg-surface-raised py-2 shadow-e3"
            >
                {noMatch ? (
                    <li role="presentation" className="flex min-h-18 flex-col items-center justify-center gap-1 px-6 text-center">
                        <Icon name="magnifyingGlass" className="size-5 text-fg-secondary" />
                        <span className="t-caption text-fg-secondary">{copy.suggestNoMatch}</span>
                    </li>
                ) : results ? (
                    <>
                        {fresh.products.length > 0 ? (
                            <>
                                {header(copy.suggestProducts, fresh.total)}
                                {fresh.products.map((p) => {
                                    const name = locale === 'bn' && p.name_bn ? p.name_bn : p.name;
                                    const age = ageLabel(p.age_min_months, p.age_max_months, ageCopy);
                                    return option(
                                        `p:${p.slug}`,
                                        localizedPath(locale, `/products/${p.slug}`),
                                        'h-14',
                                        <>
                                            <span className="size-10 shrink-0 overflow-hidden rounded-xs bg-tile-pink">
                                                {p.image ? <img src={p.image.url} alt="" width={40} height={40} loading="lazy" className="size-full object-cover" /> : null}
                                            </span>
                                            <span className="flex min-w-0 flex-1 flex-col">
                                                <span className="t-body-sm truncate text-fg-primary" lang={locale === 'bn' && !p.name_bn ? 'en' : undefined}>
                                                    {name}
                                                </span>
                                                {age ? <span className="t-caption text-fg-tertiary">{age}</span> : null}
                                            </span>
                                            <span className="t-price-current-compact shrink-0 tabular-nums text-fg-discount">{formatPrice(p.price)}</span>
                                        </>,
                                    );
                                })}
                            </>
                        ) : null}
                        {fresh.categories.length > 0 ? (
                            <>
                                {fresh.products.length > 0 ? divider : null}
                                {header(copy.suggestCategories, fresh.categories.length)}
                                {fresh.categories.map((c) =>
                                    option(
                                        `c:${c.slug}`,
                                        localizedPath(locale, `/categories/${c.slug}`),
                                        'h-11',
                                        <>
                                            <Icon name={categoryIcon(c.icon)} className="size-5 text-fg-brand" />
                                            <span className="t-body-sm min-w-0 flex-1 truncate text-fg-primary" lang={locale === 'bn' && !c.name_bn ? 'en' : undefined}>
                                                {locale === 'bn' && c.name_bn ? c.name_bn : c.name}
                                            </span>
                                            {c.count !== null ? <span className="t-caption tabular-nums text-fg-tertiary">{c.count}</span> : null}
                                        </>,
                                    ),
                                )}
                            </>
                        ) : null}
                        {fresh.brands.length > 0 ? (
                            <>
                                {fresh.products.length + fresh.categories.length > 0 ? divider : null}
                                {header(copy.suggestBrands, fresh.brands.length)}
                                {fresh.brands.map((b) =>
                                    option(
                                        `b:${b.slug}`,
                                        localizedPath(locale, `/brands/${b.slug}`),
                                        'h-11',
                                        <>
                                            <Icon name="storefront" className="size-5 text-fg-brand" />
                                            <span className="t-body-sm min-w-0 flex-1 truncate text-fg-primary" lang={locale === 'bn' && !b.name_bn ? 'en' : undefined}>
                                                {locale === 'bn' && b.name_bn ? b.name_bn : b.name}
                                            </span>
                                            {b.count !== null ? <span className="t-caption tabular-nums text-fg-tertiary">{b.count}</span> : null}
                                        </>,
                                    ),
                                )}
                            </>
                        ) : null}
                        {divider}
                        {option(
                            'all',
                            localizedPath(locale, searchPath(query)),
                            'h-12 justify-center gap-1.5',
                            <>
                                <span className="t-body-sm-strong text-fg-brand">{format(copy.suggestSeeAll, { count: String(fresh.total), query })}</span>
                                <Icon name="arrowRight" className="size-4 text-fg-brand" />
                            </>,
                        )}
                    </>
                ) : showRecent ? (
                    <>
                        <li role="presentation" className="flex h-7 items-center justify-between px-4">
                            <span className="t-overline text-fg-tertiary">{copy.recentTitle}</span>
                            <button
                                type="button"
                                onClick={() => {
                                    writeRecent([]);
                                    setRecent([]);
                                }}
                                className="t-caption-strong text-fg-brand"
                            >
                                {copy.recentClear}
                            </button>
                        </li>
                        <li role="presentation" className="flex flex-wrap gap-2 px-4 pt-1 pb-3">
                            {recent.map((r) => {
                                const i = indexOf(`r:${r}`);
                                return (
                                    <span
                                        key={r}
                                        id={optionId(i)}
                                        role="option"
                                        aria-selected={i === active}
                                        className={`t-chip flex h-control-sm min-w-14 items-center gap-1 rounded-full border bg-surface pr-1 pl-3 text-fg-primary ${i === active ? 'border-brand bg-brand-subtle' : 'border-line-default'}`}
                                    >
                                        <a href={localizedPath(locale, searchPath(r))} tabIndex={-1}>
                                            {r}
                                        </a>
                                        <button
                                            type="button"
                                            tabIndex={-1}
                                            aria-label={format(copy.recentRemove, { query: r })}
                                            onClick={() => {
                                                const next = recent.filter((x) => x !== r);
                                                writeRecent(next);
                                                setRecent(next);
                                            }}
                                            className="relative flex size-7 items-center justify-center rounded-full hover:bg-subtle before:absolute before:size-touch-min before:content-['']"
                                        >
                                            <Icon name="x" className="size-icon-sm" />
                                        </button>
                                    </span>
                                );
                            })}
                        </li>
                    </>
                ) : null}
            </ul>
        </div>
    );
}
