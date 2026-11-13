'use client';

import { useCallback, useEffect, useRef, useState, type FocusEvent, type KeyboardEvent, type MouseEvent } from 'react';

import { Icon } from '@/components/icons/Icon';
import { panelColumns, type MenuItem, type MenuLink } from '@/lib/category-tree';
import { localizedPath, type Locale } from '@/lib/i18n/config';
import { barKey, CLOSE_DELAY_MS, OPEN_DELAY_MS, panelKey } from '@/lib/megamenu-keys';

interface Props {
    locale: Locale;
    items: MenuItem[];
    label: string;
}

// Desktop mega menu, storefront spec §A4 (≥1024 only; below that MobileNav replaces it).
//
// Without JavaScript this is server-rendered nav lists: every L1/L2/L3 category is a real link,
// and CSS (`.mega-menu:not([data-enhanced])` in globals.css) reveals a panel on hover or
// focus-within. Once hydrated the component marks itself `data-enhanced`, adds the menubar/menu
// ARIA roles and takes over with the §A4.4 keyboard contract (lib/megamenu-keys.ts) and the
// 120 ms / 180 ms hover intent. The promo slot (§A4.3) is omitted until the CMS exists.
export function MegaMenu({ locale, items, label }: Props) {
    const [enhanced, setEnhanced] = useState(false);
    const [open, setOpen] = useState<number | null>(null);
    const [pendingFocus, setPendingFocus] = useState<{ item: number; column: number; row: number } | null>(null);
    const triggers = useRef<Array<HTMLAnchorElement | null>>([]);
    const links = useRef(new Map<string, HTMLAnchorElement>());
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => setEnhanced(true), []);

    const clearTimer = () => {
        if (timer.current) clearTimeout(timer.current);
        timer.current = null;
    };
    useEffect(() => clearTimer, []);

    const close = useCallback((returnFocusTo: number | null = null) => {
        setOpen(null);
        if (returnFocusTo !== null) triggers.current[returnFocusTo]?.focus();
    }, []);

    // Escape closes a hover-opened panel even when focus is elsewhere on the page.
    useEffect(() => {
        if (open === null) return;
        const onKey = (e: globalThis.KeyboardEvent) => {
            if (e.key === 'Escape') setOpen(null);
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [open]);

    // Focus moves after the panel is displayed (it is display:none while closed).
    useEffect(() => {
        if (!pendingFocus || open !== pendingFocus.item) return;
        links.current.get(key(pendingFocus.item, pendingFocus.column, pendingFocus.row))?.focus();
        setPendingFocus(null);
    }, [open, pendingFocus]);

    const columnSizes = (index: number) => {
        const item = items[index];
        return item ? panelColumns(item).map((c) => c.length) : [];
    };
    const hasPanel = (index: number) => (items[index]?.columns.length ?? 0) > 0;

    const onTriggerKeyDown = (index: number) => (e: KeyboardEvent<HTMLAnchorElement>) => {
        const action = barKey(e.key, { index, count: items.length, open: open !== null, hasPanel: hasPanel(index) });
        if (!action) return;
        e.preventDefault();
        if (action.type === 'focusItem') {
            triggers.current[action.index]?.focus();
            if (action.open) setOpen(hasPanel(action.index) ? action.index : null);
        } else if (action.type === 'openPanel') {
            setOpen(action.index);
            setPendingFocus({ item: action.index, column: 0, row: 0 });
        } else {
            close(action.index);
        }
    };

    const onPanelKeyDown = (index: number) => (e: KeyboardEvent<HTMLDivElement>) => {
        const target = e.target as HTMLElement;
        const column = Number(target.dataset.col);
        const row = Number(target.dataset.row);
        if (Number.isNaN(column) || Number.isNaN(row)) return;
        const action = panelKey(e.key, { column, row }, columnSizes(index));
        if (!action) return;
        e.preventDefault();
        e.stopPropagation();
        if (action.type === 'close') close(index);
        else links.current.get(key(index, action.column, action.row))?.focus();
    };

    const onTriggerClick = (index: number) => (e: MouseEvent<HTMLAnchorElement>) => {
        // First click opens the panel; a click on an already-open item follows its link.
        if (!enhanced || !hasPanel(index) || open === index) return;
        e.preventDefault();
        clearTimer();
        setOpen(index);
    };

    const onEnter = (index: number) => () => {
        clearTimer();
        if (open !== null) {
            setOpen(hasPanel(index) ? index : null); // swap without a close/reopen flash
            return;
        }
        if (!hasPanel(index)) return;
        timer.current = setTimeout(() => setOpen(index), OPEN_DELAY_MS);
    };
    const onLeave = () => {
        clearTimer();
        timer.current = setTimeout(() => setOpen(null), CLOSE_DELAY_MS);
    };
    const onBlur = (index: number) => (e: FocusEvent<HTMLLIElement>) => {
        if (open === index && !e.currentTarget.contains(e.relatedTarget as Node | null)) setOpen(null);
    };

    if (items.length === 0) return null;

    const role = <T extends string>(value: T) => (enhanced ? value : undefined);
    // CMS menu links (lib/content/types.ts): an external https URL stays absolute; a new-tab item opens with rel=noopener.
    const href = (link: MenuLink) => ({
        href: link.external ? link.path : localizedPath(locale, link.path),
        ...(link.newTab ? { target: '_blank', rel: 'noopener noreferrer' } : link.external ? { rel: 'noopener' } : {}),
    });

    return (
        <nav
            aria-label={label}
            data-enhanced={enhanced ? '' : undefined}
            className="mega-menu relative hidden border-y border-line-brand-subtle bg-surface px-gutter-desktop lg:block"
        >
            {enhanced && open !== null ? (
                <div aria-hidden="true" onClick={() => close()} className="absolute inset-x-0 top-full z-10 h-dvh bg-fg-primary/8" />
            ) : null}
            <div className="relative z-20 mx-auto max-w-content">
                <ul role={role('menubar')} aria-label={enhanced ? label : undefined} className="flex h-12 items-stretch gap-1 overflow-x-auto whitespace-nowrap">
                    {items.map((item, index) => {
                        const withPanel = item.columns.length > 0;
                        const triggerId = `${item.id}-trigger`;
                        const panelId = `${item.id}-panel`;
                        return (
                            <li
                                key={item.id}
                                role={role('none')}
                                className="mega-item flex"
                                onMouseEnter={onEnter(index)}
                                onMouseLeave={onLeave}
                                onBlur={onBlur(index)}
                            >
                                <a
                                    ref={(el) => {
                                        triggers.current[index] = el;
                                    }}
                                    id={triggerId}
                                    {...href(item.label)}
                                    lang={item.label.lang}
                                    role={role('menuitem')}
                                    aria-haspopup={enhanced && withPanel ? 'true' : undefined}
                                    aria-expanded={enhanced && withPanel ? open === index : undefined}
                                    aria-controls={enhanced && withPanel ? panelId : undefined}
                                    onKeyDown={onTriggerKeyDown(index)}
                                    onClick={onTriggerClick(index)}
                                    className={`t-body-sm relative inline-flex items-center gap-1 rounded-xs px-3.5 font-semibold transition-colors duration-(--tm-duration-fast) ease-standard hover:text-fg-brand ${open === index ? 'text-fg-brand' : 'text-fg-primary'}`}
                                >
                                    {item.label.label}
                                    {withPanel ? <Icon name="caretDown" className="size-3.5" /> : null}
                                    {/* Open item: coral label + 3px coral underline (Figma MegaMenuBar 123:1833). */}
                                    {open === index ? <span aria-hidden="true" className="absolute inset-x-2 bottom-0 h-0.75 rounded-full bg-brand" /> : null}
                                </a>
                                {withPanel ? (
                                    <div
                                        id={panelId}
                                        role={role('menu')}
                                        aria-labelledby={enhanced ? triggerId : undefined}
                                        data-open={open === index ? '' : undefined}
                                        onKeyDown={onPanelKeyDown(index)}
                                        className="mega-panel absolute inset-x-0 top-full max-h-120 min-h-80 gap-8 overflow-y-auto whitespace-normal rounded-b-xl bg-surface-raised p-8 shadow-e4"
                                    >
                                        {panelColumns(item).length > 0 ? (
                                            <Panel item={item} index={index} enhanced={enhanced} href={href} links={links.current} />
                                        ) : null}
                                    </div>
                                ) : null}
                            </li>
                        );
                    })}
                </ul>
            </div>
        </nav>
    );
}

function key(item: number, column: number, row: number): string {
    return `${item}:${column}:${row}`;
}

interface PanelProps {
    item: MenuItem;
    index: number;
    enhanced: boolean;
    href: (link: MenuLink) => { href: string; target?: string; rel?: string };
    links: Map<string, HTMLAnchorElement>;
}

function Panel({ item, index, enhanced, href, links }: PanelProps) {
    const role = <T extends string>(value: T) => (enhanced ? value : undefined);
    const register = (column: number, row: number) => (el: HTMLAnchorElement | null) => {
        if (el) links.set(key(index, column, row), el);
        else links.delete(key(index, column, row));
    };

    return (
        <>
            {item.columns.map((groups, column) => {
                let row = 0;
                const position = () => ({ 'data-col': column, 'data-row': row++ });
                return (
                    <div key={column} role={role('none')} className="flex min-w-0 flex-1 flex-col gap-6">
                        {column === 0 && item.viewAll ? (
                            <a
                                ref={register(0, 0)}
                                {...position()}
                                {...href(item.viewAll)}
                                role={role('menuitem')}
                                className="t-label inline-flex items-center self-start rounded-xs text-fg-link underline-offset-4 hover:underline"
                            >
                                {item.viewAll.label}
                            </a>
                        ) : null}
                        {groups.map((group) => {
                            const headingPos = position();
                            const headingId = `${item.id}-g-${column}-${headingPos['data-row']}`;
                            return (
                                <div key={group.heading.path} role={role('group')} aria-labelledby={enhanced ? headingId : undefined} className="flex flex-col gap-2">
                                    <a
                                        ref={register(column, headingPos['data-row'])}
                                        {...headingPos}
                                        id={headingId}
                                        {...href(group.heading)}
                                        lang={group.heading.lang}
                                        role={role('menuitem')}
                                        className="t-overline self-start rounded-xs text-fg-tertiary hover:text-fg-link"
                                    >
                                        {group.heading.label}
                                    </a>
                                    {group.links.length > 0 ? (
                                        <ul role={role('none')} className="flex flex-col">
                                            {group.links.map((link) => {
                                                const pos = position();
                                                return (
                                                    <li key={link.path} role={role('none')}>
                                                        <a
                                                            ref={register(column, pos['data-row'])}
                                                            {...pos}
                                                            {...href(link)}
                                                            lang={link.lang}
                                                            role={role('menuitem')}
                                                            className="t-body-sm flex min-h-7 items-center rounded-xs text-fg-secondary hover:text-fg-link"
                                                        >
                                                            {link.label}
                                                        </a>
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    ) : null}
                                </div>
                            );
                        })}
                    </div>
                );
            })}
        </>
    );
}
