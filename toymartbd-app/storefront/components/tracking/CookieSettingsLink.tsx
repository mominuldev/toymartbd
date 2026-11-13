'use client';

import type { MouseEvent } from 'react';

import { CONSENT_OPEN_EVENT, CONSENT_READY_ATTR } from './TrackingRoot';

interface Props {
    href: string;
    label: string;
    className?: string;
}

// Footer "Cookie settings". A real link to the privacy policy (works without JavaScript, and when
// tracking is off there is no banner to open); once TrackingRoot is ready it reopens the banner
// in place instead.
export function CookieSettingsLink({ href, label, className }: Props) {
    const onClick = (event: MouseEvent<HTMLAnchorElement>) => {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
        if (!document.documentElement.hasAttribute(CONSENT_READY_ATTR)) return;
        event.preventDefault();
        window.dispatchEvent(new Event(CONSENT_OPEN_EVENT));
    };
    return (
        <a href={href} onClick={onClick} className={className}>
            {label}
        </a>
    );
}
