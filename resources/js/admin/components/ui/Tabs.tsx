import type { ReactNode } from 'react';
import { NavLink } from 'react-router';
import { CountBadge } from './CountBadge';

/**
 * Figma AdminTab 185:125 — 40 high, 12 side padding, 6 gap to the count. Active: a 2px
 * bg/brand underline and a semibold text/primary label (Admin/button); inactive:
 * Admin/body in text/secondary.
 */
export function tabClass(active: boolean): string {
    return `-mb-px inline-flex h-10 shrink-0 items-center gap-1.5 border-b-2 px-3 whitespace-nowrap transition-colors duration-150 ease-standard ${
        active ? 'border-brand t-admin-button text-fg-primary' : 'border-transparent t-admin-body text-fg-secondary hover:text-fg-primary'
    }`;
}

/** The row the tabs sit on: a border/default baseline. */
export function TabList({ label, children, className = '' }: { label: string; children: ReactNode; className?: string }) {
    return (
        <nav aria-label={label} className={`scrollbar-none flex overflow-x-auto border-b border-line-default ${className}`}>
            {children}
        </nav>
    );
}

/** A tab that is a route. */
export function TabLink({ to, end, label, count }: { to: string; end?: boolean; label: string; count?: number }) {
    return (
        <NavLink to={to} end={end} className={({ isActive }) => tabClass(isActive)}>
            {label}
            {count !== undefined && <CountBadge count={count} />}
        </NavLink>
    );
}

/** A tab that filters in place (status tabs on a list). */
export function TabButton({ active, label, count, onClick }: { active: boolean; label: string; count?: number; onClick: () => void }) {
    return (
        <button type="button" aria-pressed={active} onClick={onClick} className={tabClass(active)}>
            {label}
            {count !== undefined && <CountBadge count={count} />}
        </button>
    );
}
