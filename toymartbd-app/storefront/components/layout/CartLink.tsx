'use client';

import { useEffect, useState, type ReactNode } from 'react';

import { CART_COUNT_COOKIE } from '@/lib/cart/cookies';
import { cartBadge } from '@/lib/navigation';

/** Fired by the cart forms after a JS-enhanced change: `detail` is the new item count. */
export const CART_COUNT_EVENT = 'tm:cart-count';

interface Props {
    href: string;
    /** Server-known count (dynamic pages: cart, checkout); cached pages pass 0 and read the cookie. */
    initial: number;
    labels: { empty: string; count: string };
    /** This link is the current page (tab bar on /cart). */
    current?: boolean;
    className: string;
    badgeClassName: string;
    children: ReactNode;
}

function readCount(): number | null {
    const match = document.cookie.match(new RegExp(`(?:^|; )${CART_COUNT_COOKIE}=(\\d{1,4})`));
    return match ? Number(match[1]) : null;
}

// The header / tab-bar cart link with its count badge (P4-STO-08). Pages are ISR-cached, so the
// server cannot know the visitor's cart: the count comes from the readable `tm_cart_count` cookie
// the cart Route Handlers keep in step (a number, not a credential — the token stays httpOnly),
// and from CART_COUNT_EVENT after an in-place add. Without JavaScript the badge shows only where
// the page knows the count (cart, checkout).
export function CartLink({ href, initial, labels, current = false, className, badgeClassName, children }: Props) {
    const [count, setCount] = useState(initial);

    useEffect(() => {
        const fromCookie = readCount();
        if (fromCookie !== null && initial === 0) setCount(fromCookie);
        const onChange = (event: Event) => {
            const next = (event as CustomEvent<number>).detail;
            if (typeof next === 'number') setCount(next);
        };
        window.addEventListener(CART_COUNT_EVENT, onChange);
        return () => window.removeEventListener(CART_COUNT_EVENT, onChange);
    }, [initial]);

    const badge = cartBadge(count);
    return (
        // Named by a visually hidden label rather than aria-label: the mobile tab's visible "Cart" +
        // badge would not match an aria-label word for word (WCAG 2.5.3, Phase 9 audit A-6). The
        // visible label is hidden from assistive tech instead, since the hidden one repeats it.
        <a href={href} aria-current={current ? 'page' : undefined} className={className}>
            <span className="sr-only">{badge ? labels.count.replace('{count}', badge) : labels.empty}</span>
            <span aria-hidden="true" className="contents">
                {children}
            </span>
            {badge ? (
                <span aria-hidden="true" className={badgeClassName}>
                    {badge}
                </span>
            ) : null}
        </a>
    );
}
