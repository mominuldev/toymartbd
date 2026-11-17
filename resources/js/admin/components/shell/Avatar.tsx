/** Two initials from a name; a single word gives one. Works for Latin and Bangla names. */
export function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    const letters = parts.length > 1 ? [parts[0], parts[parts.length - 1]] : parts;
    return letters.map((part) => Array.from(part ?? '')[0] ?? '').join('').toUpperCase();
}

const sizes = {
    sm: 'size-8 text-xs/none',
    lg: 'size-16 text-xl/none',
} as const;

/**
 * Figma Avatar 52:483 — Small (32) or Large (64) round, border/default hairline. The person's
 * profile photo (My account, B · 40) when they have one, otherwise initials in the display face
 * at 700 weight. Decorative here: the name is always shown or announced next to it.
 */
export function Avatar({ name, src, size = 'sm' }: { name: string; src?: string | null; size?: keyof typeof sizes }) {
    if (src) {
        return <img src={src} alt="" aria-hidden className={`${sizes[size]} shrink-0 rounded-full border border-line-default object-cover`} />;
    }

    return (
        <span aria-hidden className={`grid ${sizes[size]} shrink-0 place-items-center rounded-full border border-line-default font-display-latin font-bold text-fg-primary`}>
            {initials(name)}
        </span>
    );
}
