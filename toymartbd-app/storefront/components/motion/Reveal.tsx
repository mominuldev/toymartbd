'use client';

import { animate, inView } from 'motion';
import { useEffect, useRef, type ReactNode } from 'react';

import { duration, ease, prefersReducedMotion, space } from '@/lib/motion';

// Scroll reveal (motion.dev `inView` + `animate`). Progressive and CLS-free: the server renders
// the content fully visible, so it works with JavaScript off and nothing above the fold ever
// animates (no LCP delay). After hydration only elements still below the viewport are faded down,
// then revealed — opacity and transform only, never layout — when they scroll in. Honours
// prefers-reduced-motion (renders as-is).

export function Reveal({ children, className, as: Tag = 'div' }: { children: ReactNode; className?: string; as?: 'div' | 'li' | 'section' }) {
    const ref = useRef<HTMLElement | null>(null);

    useEffect(() => {
        const el = ref.current;
        if (!el || prefersReducedMotion()) return;
        if (el.getBoundingClientRect().top < window.innerHeight) return; // already on screen: leave it alone

        el.style.opacity = '0';
        el.style.transform = `translateY(${space('3')}px)`;
        const stop = inView(
            el,
            () => {
                animate(el, { opacity: 1, transform: 'translateY(0px)' }, { duration: duration('slow'), ease: ease('decelerate') });
            },
            { amount: 0.15 },
        );
        return () => {
            stop();
            el.style.opacity = '';
            el.style.transform = '';
        };
    }, []);

    return (
        <Tag ref={(node: HTMLElement | null) => void (ref.current = node)} className={className}>
            {children}
        </Tag>
    );
}
