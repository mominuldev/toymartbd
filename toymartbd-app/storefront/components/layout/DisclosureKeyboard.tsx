'use client';

import { useEffect } from 'react';

// Keyboard layer for the storefront's <details> pop-overs (UI-UX spec §7: "full keyboard
// operability including the filter sheet and modals · focus trapped in modals and returned on
// close"). The disclosures work without JavaScript; hydrated, every `details[data-dismissible]`
//   • closes on Escape and returns focus to its <summary>, and
//   • when it holds an `aria-modal="true"` panel (the mobile filter sheet), moves focus into the
//     panel on open and keeps Tab / Shift+Tab inside it until it closes.
// Mounted once by PageShell; it reads the DOM, so no component needs to pass refs.

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';

function focusables(root: HTMLElement): HTMLElement[] {
    return Array.from(root.querySelectorAll<HTMLElement>(FOCUSABLE)).filter((el) => el.getClientRects().length > 0 && !el.closest('[inert]'));
}

/** The innermost open dismissible disclosure holding focus. */
function activeDisclosure(): HTMLDetailsElement | null {
    const open = Array.from(document.querySelectorAll<HTMLDetailsElement>('details[data-dismissible][open]'));
    return open.filter((d) => d.contains(document.activeElement)).pop() ?? null;
}

const modalPanel = (details: HTMLDetailsElement) => details.querySelector<HTMLElement>(':scope > [aria-modal="true"]');

export function DisclosureKeyboard() {
    useEffect(() => {
        // `toggle` does not bubble: listen in the capture phase.
        const onToggle = (event: Event) => {
            const details = event.target;
            if (!(details instanceof HTMLDetailsElement) || !details.matches('[data-dismissible]') || !details.open) return;
            const panel = modalPanel(details);
            if (panel) focusables(panel)[0]?.focus();
        };

        const onKey = (event: KeyboardEvent) => {
            const details = activeDisclosure();
            if (!details) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                details.open = false;
                details.querySelector<HTMLElement>(':scope > summary')?.focus();
                return;
            }
            const panel = modalPanel(details);
            if (event.key !== 'Tab' || !panel) return;
            const items = focusables(panel);
            if (items.length === 0) return;
            const first = items[0]!;
            const last = items[items.length - 1]!;
            const inside = panel.contains(document.activeElement);
            if (event.shiftKey && (document.activeElement === first || !inside)) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !inside)) {
                event.preventDefault();
                first.focus();
            }
        };

        document.addEventListener('toggle', onToggle, true);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('toggle', onToggle, true);
            document.removeEventListener('keydown', onKey);
        };
    }, []);

    return null;
}
