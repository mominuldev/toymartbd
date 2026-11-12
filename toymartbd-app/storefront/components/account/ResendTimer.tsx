'use client';

import { useEffect, useState, type ReactNode } from 'react';

import { clock } from '@/lib/account/clock';

interface Props {
    /** When the resend becomes allowed (ms since epoch). */
    until: number;
    /** "Resend code in {time}". */
    waitLabel: string;
    /** The resend button (a form submit), shown when the wait is over. */
    children: ReactNode;
}

// "Resend code in 0:42" (Figma 237:9412). Without JavaScript the resend button simply shows — the API
// enforces the cooldown and says how long to wait. Hydrated, it counts down, then shows the button.
export function ResendTimer({ until, waitLabel, children }: Props) {
    const [now, setNow] = useState<number | null>(null);

    useEffect(() => {
        setNow(Date.now());
        const id = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(id);
    }, []);

    const left = now === null ? 0 : Math.ceil((until - now) / 1000);
    if (left > 0) {
        return (
            <p className="t-body-sm text-fg-secondary tabular-nums" aria-live="off">
                {waitLabel.replace('{time}', clock(left))}
            </p>
        );
    }
    return <>{children}</>;
}
