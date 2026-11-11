/** Figma Kbd 185:26 — a keyboard shortcut hint, JetBrains Mono on a surface chip. */
export function Kbd({ children, className = '' }: { children: string; className?: string }) {
    return (
        <kbd className={`inline-flex h-5 shrink-0 items-center rounded-xs border border-line-default bg-surface px-1.5 t-admin-mono-sm text-fg-secondary ${className}`}>
            {children}
        </kbd>
    );
}
