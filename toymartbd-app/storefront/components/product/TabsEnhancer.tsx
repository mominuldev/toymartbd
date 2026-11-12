'use client';

import { useEffect } from 'react';

// Progressive enhancement for the desktop ProductInfoPanel tabs. Without JavaScript the tabs are
// fragment links and CSS `:target` shows the panel (product.css). Hydrated, they switch in place —
// no page jump — keep the URL hash in step for sharing, and follow the WAI tabs keyboard pattern
// (ArrowLeft/ArrowRight/Home/End move between tabs; the active tab is the only tab stop).
export function TabsEnhancer({ rootId }: { rootId: string }) {
    useEffect(() => {
        const root = document.getElementById(rootId);
        if (!root) return;
        const tabs = Array.from(root.querySelectorAll<HTMLAnchorElement>('.pdp-tab'));
        const panels = Array.from(root.querySelectorAll<HTMLElement>('.pdp-panel'));
        if (tabs.length === 0) return;

        const activate = (index: number, focus: boolean) => {
            tabs.forEach((tab, i) => {
                tab.setAttribute('aria-selected', String(i === index));
                tab.tabIndex = i === index ? 0 : -1;
            });
            panels.forEach((panel) => panel.toggleAttribute('data-active', panel.id === tabs[index]?.hash.slice(1)));
            if (focus) tabs[index]?.focus();
        };

        // The CSS :target (what the no-JS view shows) or the hash decides the first active tab.
        const fromHash = () => {
            const target = root.querySelector<HTMLElement>('.pdp-panel:target')?.id;
            const hash = target ? `#${target}` : window.location.hash;
            return tabs.findIndex((tab) => tab.hash === hash);
        };
        activate(Math.max(0, fromHash()), false);
        root.dataset.enhanced = '';
        const onHash = () => {
            const index = fromHash();
            if (index >= 0) activate(index, false);
        };
        window.addEventListener('hashchange', onHash);

        const onClick = (event: MouseEvent) => {
            const index = tabs.indexOf(event.currentTarget as HTMLAnchorElement);
            if (index < 0) return;
            event.preventDefault();
            activate(index, false);
            history.replaceState(null, '', tabs[index]?.hash);
        };
        const onKey = (event: KeyboardEvent) => {
            const current = tabs.indexOf(event.currentTarget as HTMLAnchorElement);
            const last = tabs.length - 1;
            const next =
                event.key === 'ArrowRight' ? (current + 1) % tabs.length : event.key === 'ArrowLeft' ? (current - 1 + tabs.length) % tabs.length : event.key === 'Home' ? 0 : event.key === 'End' ? last : -1;
            if (next < 0) return;
            event.preventDefault();
            activate(next, true);
            history.replaceState(null, '', tabs[next]?.hash);
        };
        tabs.forEach((tab) => {
            tab.addEventListener('click', onClick);
            tab.addEventListener('keydown', onKey);
        });
        return () => {
            window.removeEventListener('hashchange', onHash);
            tabs.forEach((tab) => {
                tab.removeEventListener('click', onClick);
                tab.removeEventListener('keydown', onKey);
            });
            delete root.dataset.enhanced;
        };
    }, [rootId]);

    return null;
}
