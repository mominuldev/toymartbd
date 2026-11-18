import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState, type CSSProperties, type KeyboardEvent } from 'react';
import { createPortal } from 'react-dom';
import { Link } from 'react-router';
import { BuildingsIcon, CaretUpDownIcon, CheckIcon, GearSixIcon, StorefrontIcon, WarehouseIcon, type Icon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import type { WorkingBranchLocation } from './workingBranch';

/** 'always' = drawer (labels), 'never' = collapsed rail (icon only), 'wide' = rail below xl, labels from xl. */
export type SidebarMode = 'always' | 'never' | 'wide';

const typeIcon: Record<string, Icon> = { warehouse: WarehouseIcon, retail: StorefrontIcon, pickup: StorefrontIcon };

type Props = {
    multi: boolean;
    locations: WorkingBranchLocation[];
    current: WorkingBranchLocation | null;
    onSelect: (id: number) => void;
    /** Where "Manage branches" goes; null hides it (no settings.view). */
    manageHref: string | null;
    mode: SidebarMode;
    onNavigate?: () => void;
};

/**
 * Sidebar branch switcher (Figma BranchSwitcher 187:102; open 411:69684, single 411:69917).
 * Multiple branches: a menu button — buildings icon, the working branch, "area · N branches",
 * caret — opening the BranchMenu (surface-raised e3, the ActionMenu mechanics): "WORKING
 * BRANCH", one menuitemradio per active branch with a check on the chosen one, "Manage
 * branches", and a hint. A single shop renders nothing. In the collapsed rail it is
 * an icon-only button whose menu opens beside the rail.
 */
export function BranchSwitcher({ multi, locations, current, onSelect, manageHref, mode, onNavigate }: Props) {
    const { t, locale } = useI18n();
    const [open, setOpen] = useState<false | 'first' | 'last'>(false);
    const [style, setStyle] = useState<CSSProperties>({ position: 'fixed' });
    const menuId = useId();
    const button = useRef<HTMLButtonElement>(null);
    const menu = useRef<HTMLDivElement>(null);

    const pick = (always: string, never: string, wide: string) => (mode === 'always' ? always : mode === 'never' ? never : wide);
    const onlyExpanded = pick('flex', 'hidden', 'hidden xl:flex');
    const onlyRail = pick('hidden', '', 'xl:hidden');

    const nameOf = (l: WorkingBranchLocation) => (locale === 'bn' && l.name_bn ? l.name_bn : l.name);
    const caption = (l: WorkingBranchLocation, tail: string) => [l.area, tail].filter(Boolean).join(' · ');

    const close = useCallback((refocus: boolean) => {
        setOpen(false);
        if (refocus) button.current?.focus();
    }, []);

    /** Under the button and as wide as it; beside it when the button is the narrow rail one. */
    const place = useCallback(() => {
        if (!button.current || !menu.current) return;
        const box = button.current.getBoundingClientRect();
        const height = menu.current.offsetHeight;
        if (box.width < 120) {
            setStyle({ position: 'fixed', insetInlineStart: box.right + 8, top: Math.max(8, Math.min(box.top, window.innerHeight - height - 8)) });
            return;
        }
        setStyle({ position: 'fixed', insetInlineStart: box.left, width: box.width, top: box.bottom + 6 });
    }, []);

    useLayoutEffect(() => {
        if (!open || !menu.current) return;
        place();
        const entries = menu.current.querySelectorAll<HTMLElement>('[role^="menuitem"]');
        const checked = menu.current.querySelector<HTMLElement>('[aria-checked="true"]');
        (open === 'last' ? entries[entries.length - 1] : (checked ?? entries[0]))?.focus();
    }, [open, place]);

    useEffect(() => {
        if (!open) return;
        const outside = (event: MouseEvent) => {
            const target = event.target as Node;
            if (!menu.current?.contains(target) && !button.current?.contains(target)) setOpen(false);
        };
        document.addEventListener('mousedown', outside);
        window.addEventListener('resize', place);
        return () => {
            document.removeEventListener('mousedown', outside);
            window.removeEventListener('resize', place);
        };
    }, [open, place]);

    if (!current) return null;

    const name = nameOf(current);

    // A single shop shows no branch block at all (owner, 2026-11-16; Figma 411:69917): the brand row runs straight into the nav.
    if (!multi) return null;

    const onButtonKey = (event: KeyboardEvent<HTMLButtonElement>) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            setOpen(event.key === 'ArrowUp' ? 'last' : 'first');
        }
    };

    const onMenuKey = (event: KeyboardEvent<HTMLDivElement>) => {
        const entries = Array.from(menu.current?.querySelectorAll<HTMLElement>('[role^="menuitem"]') ?? []);
        const at = entries.indexOf(document.activeElement as HTMLElement);
        const move = (index: number) => {
            event.preventDefault();
            entries[(index + entries.length) % entries.length]?.focus();
        };
        if (event.key === 'ArrowDown') move(at + 1);
        else if (event.key === 'ArrowUp') move(at - 1);
        else if (event.key === 'Home') move(0);
        else if (event.key === 'End') move(entries.length - 1);
        else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close(true);
        } else if (event.key === 'Tab') close(true); // back to the button first, so Tab moves on from it (the menu is portalled)
    };

    const itemClass = 'flex w-full items-center gap-2.5 rounded-sm px-2.5 py-2 text-start outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-line-focus max-lg:min-h-11';

    return (
        <div className={`flex shrink-0 ${pick('px-3 pb-2', 'justify-center px-2 pb-2 xl:h-16 xl:items-center', 'justify-center px-2 pb-2 xl:justify-stretch xl:px-3')}`}>
            <button
                ref={button}
                type="button"
                aria-haspopup="menu"
                aria-expanded={Boolean(open)}
                aria-controls={open ? menuId : undefined}
                title={mode === 'always' ? undefined : name}
                onClick={() => (open ? close(false) : setOpen('first'))}
                onKeyDown={onButtonKey}
                className={`flex min-w-0 items-center gap-2.5 rounded-sm border text-start transition-colors duration-150 ease-standard hover:bg-subtle ${
                    open ? 'border-line-focus bg-subtle' : 'border-line-default'
                } ${pick('w-full px-2.5 py-2', 'size-10 justify-center', 'size-10 justify-center xl:h-14 xl:w-full xl:justify-start xl:px-2.5')}`}
            >
                {/* The name is the visible text (WCAG 2.5.3), after a hidden "Working branch:"; in the rail only the name. */}
                <span className="sr-only">{`${t('branchSwitcher.label')}: `}</span>
                <BuildingsIcon size={18} aria-hidden className="shrink-0 text-fg-primary" />
                <span className={`sr-only ${onlyRail}`}>{name}</span>
                <span className={`min-w-0 flex-1 flex-col ${onlyExpanded}`}>
                    <span className="truncate t-admin-body-strong text-fg-primary">{name}</span>
                    <span className="truncate t-admin-caption text-fg-tertiary">{caption(current, t('branchSwitcher.count', { count: String(locations.length) }))}</span>
                </span>
                <CaretUpDownIcon size={16} aria-hidden className={`shrink-0 text-fg-secondary ${onlyExpanded}`} />
            </button>
            {/* Portalled: the sticky sidebar is its own stacking context, so a menu inside it would sit
                under the page's own sticky cards. The wrapper keeps the sidebar's admin-dark theme. */}
            {open &&
                createPortal(
                    <div data-theme="admin-dark" className="contents">
                        <div
                            ref={menu}
                            id={menuId}
                            role="menu"
                            aria-label={t('branchSwitcher.menu')}
                            style={style}
                            onKeyDown={onMenuKey}
                            className="z-50 flex w-56 flex-col gap-0.5 rounded-md border border-line-default bg-surface-raised p-1.5 shadow-e3"
                        >
                            <p aria-hidden className="px-2.5 pt-1.5 pb-1 t-admin-overline text-fg-tertiary">
                                {t('branchSwitcher.label')}
                            </p>
                            {locations.map((l) => {
                                const selected = l.id === current.id;
                                const TypeIcon = (l.type && typeIcon[l.type]) || BuildingsIcon;
                                const typeLabel = l.type ? t(`branchSwitcher.type.${l.type}` as MessageKey) : '';
                                return (
                                    <button
                                        key={l.id}
                                        type="button"
                                        role="menuitemradio"
                                        aria-checked={selected}
                                        tabIndex={-1}
                                        onClick={() => {
                                            onSelect(l.id);
                                            close(true);
                                        }}
                                        className={`${itemClass} ${selected ? 'bg-brand-subtle' : 'hover:bg-subtle focus-visible:bg-subtle'}`}
                                    >
                                        <TypeIcon size={18} aria-hidden className={`shrink-0 ${selected ? 'text-fg-brand' : 'text-fg-secondary'}`} />
                                        <span className="flex min-w-0 flex-1 flex-col">
                                            <span className="truncate t-admin-body-strong text-fg-primary">{nameOf(l)}</span>
                                            <span className="truncate t-admin-caption text-fg-tertiary">{caption(l, typeLabel)}</span>
                                        </span>
                                        {selected && <CheckIcon size={16} aria-hidden className="shrink-0 text-fg-brand" />}
                                    </button>
                                );
                            })}
                            {manageHref && (
                                <>
                                    <div aria-hidden className="h-px bg-line-default" />
                                    <Link
                                        to={manageHref}
                                        role="menuitem"
                                        tabIndex={-1}
                                        onClick={() => {
                                            setOpen(false);
                                            onNavigate?.();
                                        }}
                                        className={`${itemClass} t-admin-body text-fg-primary hover:bg-subtle focus-visible:bg-subtle`}
                                    >
                                        <GearSixIcon size={18} aria-hidden className="shrink-0 text-fg-secondary" />
                                        {t('branchSwitcher.manage')}
                                    </Link>
                                </>
                            )}
                            <p className="px-2.5 pt-1 pb-1.5 t-admin-caption text-fg-tertiary">{t('branchSwitcher.hint')}</p>
                        </div>
                    </div>,
                    document.body,
                )}
        </div>
    );
}
