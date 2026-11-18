import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState, type CSSProperties, type KeyboardEvent, type ReactNode } from 'react';
import { Link } from 'react-router';
import { DotsThreeIcon } from '@phosphor-icons/react';
import { IconButton, type IconButtonVariant } from './IconButton';

/**
 * A `disabled` item stays in the menu (so related rows show the same menu) but does nothing;
 * `hint` says why, as its accessible description and tooltip.
 */
export type ActionMenuItem = { key: string; label: string; icon?: ReactNode } & (
    | { to: string; onSelect?: never; disabled?: never; hint?: never }
    | { onSelect: () => void; to?: never; disabled?: never; hint?: never }
    | { disabled: true; hint: string; to?: never; onSelect?: never }
);

const itemBase =
    'flex h-9 w-full items-center gap-2 rounded-xs px-3 text-start t-admin-body whitespace-nowrap outline-none focus-visible:bg-subtle focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-line-focus';
const itemClass = `${itemBase} text-fg-primary hover:bg-subtle`;
const disabledItemClass = `${itemBase} cursor-not-allowed text-fg-disabled`;

/**
 * Row and toolbar ⋯ overflow (AdminIconButton + the surface-raised e3 menu the order bar's
 * More menu and the user menu use). WAI-ARIA menu button: Enter / Space / ↓ open on the first
 * item, ↑ on the last; ↑ ↓ Home End move; Escape closes and returns focus to the button; Tab
 * closes. The menu is positioned `fixed` from the button's box so a clipped table card
 * (overflow hidden) never cuts it off, and flips upwards when there is no room below.
 */
export function ActionMenu({ label, items, variant = 'ghost', size = 'sm' }: { label: string; items: ActionMenuItem[]; variant?: IconButtonVariant; size?: 'sm' | 'md' }) {
    const [open, setOpen] = useState<false | 'first' | 'last'>(false);
    const [style, setStyle] = useState<CSSProperties>({ position: 'fixed' });
    const menuId = useId();
    const button = useRef<HTMLButtonElement>(null);
    const menu = useRef<HTMLDivElement>(null);

    const close = useCallback((refocus: boolean) => {
        setOpen(false);
        if (refocus) button.current?.focus();
    }, []);

    /** Pins the menu under (or, without room, over) the button, end-aligned to it. */
    const place = useCallback(() => {
        if (!button.current || !menu.current) return;
        const box = button.current.getBoundingClientRect();
        const height = menu.current.offsetHeight;
        const below = window.innerHeight - box.bottom;
        const up = below < height + 8 && box.top > below;
        setStyle({
            position: 'fixed',
            insetInlineEnd: document.documentElement.clientWidth - box.right,
            ...(up ? { bottom: window.innerHeight - box.top + 4 } : { top: box.bottom + 4 }),
        });
    }, []);

    useLayoutEffect(() => {
        if (!open || !menu.current) return;
        place();
        const entries = menu.current.querySelectorAll<HTMLElement>('[role="menuitem"]');
        (open === 'last' ? entries[entries.length - 1] : entries[0])?.focus();
    }, [open, place]);

    useEffect(() => {
        if (!open) return;
        const outside = (event: MouseEvent) => {
            const target = event.target as Node;
            if (!menu.current?.contains(target) && !button.current?.contains(target)) setOpen(false);
        };
        // The menu is fixed to the viewport: it follows its button through any scroll or resize.
        document.addEventListener('mousedown', outside);
        window.addEventListener('scroll', place, true);
        window.addEventListener('resize', place);
        return () => {
            document.removeEventListener('mousedown', outside);
            window.removeEventListener('scroll', place, true);
            window.removeEventListener('resize', place);
        };
    }, [open, place]);

    const onButtonKey = (event: KeyboardEvent<HTMLButtonElement>) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            setOpen(event.key === 'ArrowUp' ? 'last' : 'first');
        }
    };

    const onMenuKey = (event: KeyboardEvent<HTMLDivElement>) => {
        const entries = Array.from(menu.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? []);
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
        } else if (event.key === 'Tab') setOpen(false);
    };

    if (items.length === 0) return null;

    return (
        <>
            <IconButton
                ref={button}
                variant={variant}
                size={size}
                aria-label={label}
                aria-haspopup="menu"
                aria-expanded={Boolean(open)}
                aria-controls={open ? menuId : undefined}
                onClick={() => (open ? close(false) : setOpen('first'))}
                onKeyDown={onButtonKey}
            >
                <DotsThreeIcon size={size === 'sm' ? 18 : 20} weight="bold" aria-hidden />
            </IconButton>
            {open && (
                <div
                    ref={menu}
                    id={menuId}
                    role="menu"
                    aria-label={label}
                    style={style}
                    onKeyDown={onMenuKey}
                    className="z-50 min-w-48 rounded-sm border border-line-default bg-surface-raised p-1 shadow-e3"
                >
                    {items.map((item) =>
                        item.disabled ? (
                            <button
                                key={item.key}
                                type="button"
                                role="menuitem"
                                tabIndex={-1}
                                aria-disabled="true"
                                aria-description={item.hint}
                                title={item.hint}
                                className={disabledItemClass}
                            >
                                {item.icon}
                                {item.label}
                            </button>
                        ) : item.to !== undefined ? (
                            <Link key={item.key} to={item.to} role="menuitem" tabIndex={-1} className={itemClass} onClick={() => setOpen(false)}>
                                {item.icon}
                                {item.label}
                            </Link>
                        ) : (
                            <button
                                key={item.key}
                                type="button"
                                role="menuitem"
                                tabIndex={-1}
                                className={itemClass}
                                onClick={() => {
                                    setOpen(false);
                                    item.onSelect();
                                }}
                            >
                                {item.icon}
                                {item.label}
                            </button>
                        ),
                    )}
                </div>
            )}
        </>
    );
}
