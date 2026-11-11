/** Two initials from a name; a single word gives one. Works for Latin and Bangla names. */
function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    const letters = parts.length > 1 ? [parts[0], parts[parts.length - 1]] : parts;
    return letters.map((part) => Array.from(part ?? '')[0] ?? '').join('').toUpperCase();
}

/**
 * Figma Avatar 52:483, Small · Initials — 32 round, border/default hairline, initials in
 * the display face at 700 weight. Decorative here: the name is always shown or announced
 * next to it.
 */
export function Avatar({ name }: { name: string }) {
    return (
        <span aria-hidden className="grid size-8 shrink-0 place-items-center rounded-full border border-line-default font-display-latin text-xs/none font-bold text-fg-primary">
            {initials(name)}
        </span>
    );
}
