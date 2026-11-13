'use client';

import { useRouter } from 'next/navigation';
import { startTransition, useEffect } from 'react';

import { blockId, BRIDGE_SOURCE, parseToCanvas, type FromCanvas } from '@/lib/content/preview-bridge';

// The live-editor half of the homepage draft preview (Phase 8). Rendered only on the draft page,
// and it does nothing unless that page sits in a frame whose parent is the admin's origin — the
// same preview opened in a tab (the builder's "Preview" button) stays a plain page.
//
// Inside the builder:
//   • hovering a section outlines it and shows its name (`data-tm-hover`, styled in globals.css);
//   • clicking a section selects it in the builder's settings panel (`data-tm-selected`);
//   • links do not navigate — the frame must stay on the draft;
//   • after each autosave the builder sends `refresh`, and router.refresh() re-renders the server
//     components in place: no reload flash, scroll position and selection kept.
// Section names come from the builder (it already shows them) and are written to
// `data-tm-label`; nothing here formats or computes content.

const BLOCK = '[data-block-id]';

function blockOf(target: EventTarget | null): HTMLElement | null {
    return target instanceof Element ? target.closest<HTMLElement>(BLOCK) : null;
}

export function PreviewBridge({ adminOrigin }: { adminOrigin: string | null }) {
    const router = useRouter();

    useEffect(() => {
        if (!adminOrigin || window.parent === window) return;
        const parent = window.parent;
        const root = document.documentElement;
        const post = (message: FromCanvas) => parent.postMessage(message, adminOrigin);

        let selected: string | null = null;
        let hovered: HTMLElement | null = null;
        let labels: Record<string, string> = {};

        // The server re-render after a refresh replaces the block elements, so the editor's
        // attributes are re-applied to whatever is in the DOM now.
        const paint = () => {
            for (const el of document.querySelectorAll<HTMLElement>(BLOCK)) {
                const id = el.dataset.blockId ?? '';
                const label = labels[id];
                if (label) el.dataset.tmLabel = label;
                else delete el.dataset.tmLabel;
                if (id === selected) el.dataset.tmSelected = '';
                else delete el.dataset.tmSelected;
            }
        };

        const setHover = (el: HTMLElement | null) => {
            if (hovered === el) return;
            if (hovered) delete hovered.dataset.tmHover;
            hovered = el;
            if (el) el.dataset.tmHover = '';
        };

        const onMessage = (event: MessageEvent) => {
            if (event.origin !== adminOrigin || event.source !== parent) return;
            const message = parseToCanvas(event.data);
            if (!message) return;
            if (message.type === 'refresh') {
                startTransition(() => router.refresh());
            } else if (message.type === 'labels') {
                labels = message.labels;
                paint();
            } else {
                selected = message.id;
                paint();
                if (message.scroll && selected !== null) {
                    document.querySelector<HTMLElement>(`[data-block-id="${CSS.escape(selected)}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        };

        const onOver = (event: MouseEvent) => setHover(blockOf(event.target));
        const onLeave = () => setHover(null);

        const onClick = (event: MouseEvent) => {
            const target = event.target instanceof Element ? event.target : null;
            // Stay on the draft: no link or form leaves the frame.
            if (target?.closest('a[href], button[type="submit"]')) event.preventDefault();
            const block = blockOf(target);
            const id = blockId(block?.dataset.blockId);
            if (!block || id === null) return;
            selected = id;
            paint();
            post({ source: BRIDGE_SOURCE, type: 'select', id });
        };
        const onSubmit = (event: SubmitEvent) => event.preventDefault();

        const observer = new MutationObserver(() => paint());
        observer.observe(document.body, { childList: true, subtree: true });

        root.dataset.tmEditing = '';
        window.addEventListener('message', onMessage);
        document.addEventListener('mouseover', onOver);
        root.addEventListener('mouseleave', onLeave);
        document.addEventListener('click', onClick, true);
        document.addEventListener('submit', onSubmit, true);
        post({ source: BRIDGE_SOURCE, type: 'ready' });

        return () => {
            observer.disconnect();
            delete root.dataset.tmEditing;
            window.removeEventListener('message', onMessage);
            document.removeEventListener('mouseover', onOver);
            root.removeEventListener('mouseleave', onLeave);
            document.removeEventListener('click', onClick, true);
            document.removeEventListener('submit', onSubmit, true);
        };
    }, [adminOrigin, router]);

    return null;
}
