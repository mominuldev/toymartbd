import { useEffect, useState } from 'react';

export type Breakpoint = 'sm' | 'lg' | 'xl';

const queries = { xl: '(min-width: 80rem)', lg: '(min-width: 64rem)' } as const;

function read(): Breakpoint {
    // jsdom (tests) has no matchMedia: lay out as the 1440 design.
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return 'xl';
    if (window.matchMedia(queries.xl).matches) return 'xl';
    if (window.matchMedia(queries.lg).matches) return 'lg';
    return 'sm';
}

/**
 * The media library changes behaviour, not only layout, across the token breakpoints:
 * xl (1280+) keeps the detail panel inline; lg (1024) turns it into an overlay drawer;
 * below lg the rail becomes selects and a bottom selection bar opens the drawer.
 */
export function useBreakpoint(): Breakpoint {
    const [bp, setBp] = useState<Breakpoint>(read);
    useEffect(() => {
        if (typeof window.matchMedia !== 'function') return;
        const lists = [window.matchMedia(queries.xl), window.matchMedia(queries.lg)];
        const update = () => setBp(read());
        lists.forEach((l) => l.addEventListener('change', update));
        return () => lists.forEach((l) => l.removeEventListener('change', update));
    }, []);
    return bp;
}
