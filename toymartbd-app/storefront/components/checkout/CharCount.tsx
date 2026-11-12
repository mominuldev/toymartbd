'use client';

import { useEffect, useState } from 'react';

/** "25 / 500" under a textarea (Figma checkout Full address), kept live once hydrated. */
export function CharCount({ target, initial, max, template }: { target: string; initial: number; max: number; template: string }) {
    const [count, setCount] = useState(initial);
    useEffect(() => {
        const el = document.getElementById(target);
        if (!(el instanceof HTMLTextAreaElement)) return;
        const update = () => setCount(el.value.length);
        update();
        el.addEventListener('input', update);
        return () => el.removeEventListener('input', update);
    }, [target]);
    return <span className="t-caption shrink-0 text-fg-secondary tabular-nums">{template.replace('{count}', String(count)).replace('{max}', String(max))}</span>;
}
