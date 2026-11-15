'use client';

import { useEffect, useRef, useState, type FormEvent } from 'react';

import { Icon } from '@/components/icons/Icon';
import { CART_COUNT_EVENT } from '@/components/layout/CartLink';
import type { CartActionResponse } from '@/lib/cart/types';

interface Props {
    variantId: number;
    locale: string;
    label: string;
    cartHref: string;
    labels: { added: string; viewCart: string; dismiss: string; failed: string; notices: Record<string, string> };
}

type Status = { kind: 'added' } | { kind: 'error'; message: string } | null;

/** How long the toast stays up. Long enough to read and reach "View cart"; hover or focus keeps it. */
const TOAST_MS = 5000;

const BUTTON =
    "relative flex cursor-pointer size-control-sm shrink-0 items-center justify-center rounded-full border-(length:--tm-border-width-strong) border-line-brand-subtle bg-surface text-fg-brand transition-colors duration-(--tm-duration-fast) ease-standard hover:bg-subtle lg:size-control-md before:absolute before:size-touch-min before:content-['']";

// The product card's cart button for a single-variant product: the same POST /api/cart/items the
// PDP uses. Without JavaScript the form posts and the Route Handler 303s to the cart. Once
// hydrated, the add happens in place: the header count updates and a toast confirms it with a
// "View cart" link (or shows the API's own reason it failed). The toast is a polite live region,
// sits above the mobile tab bar, stays while hovered or focused, and closes on Escape or after
// TOAST_MS. A second tap while one add is in flight is ignored, not doubled. Money is never
// computed here (rule 1).
export function CardCartButton({ variantId, locale, label, cartHref, labels }: Props) {
    const [status, setStatus] = useState<Status>(null);
    const [held, setHeld] = useState(false);
    const busy = useRef(false);

    useEffect(() => {
        if (status === null || held) return;
        const id = window.setTimeout(() => setStatus(null), TOAST_MS);
        return () => window.clearTimeout(id);
    }, [status, held]);

    useEffect(() => {
        if (status === null) return;
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') setStatus(null);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [status]);

    const onSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (busy.current) return;
        busy.current = true;
        const form = event.currentTarget;
        form.setAttribute('aria-busy', 'true');
        setStatus(null);
        void (async () => {
            try {
                const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
                const result = (await response.json()) as CartActionResponse;
                if (typeof result.itemCount === 'number') window.dispatchEvent(new CustomEvent(CART_COUNT_EVENT, { detail: result.itemCount }));
                if (result.ok) {
                    setStatus({ kind: 'added' });
                } else {
                    const message = result.notice ? (labels.notices[result.notice.message] ?? result.notice.message) : (labels.notices.failed ?? '');
                    setStatus({ kind: 'error', message: labels.failed.replace('{message}', message ?? '') });
                }
            } catch {
                setStatus({ kind: 'error', message: labels.notices.failed ?? '' });
            } finally {
                busy.current = false;
                form.removeAttribute('aria-busy');
            }
        })();
    };

    return (
        <form method="post" action="/api/cart/items" onSubmit={onSubmit} className="contents">
            <input type="hidden" name="variant_id" value={variantId} />
            <input type="hidden" name="quantity" value="1" />
            <input type="hidden" name="intent" value="add" />
            <input type="hidden" name="locale" value={locale} />
            <button type="submit" aria-label={label} className={BUTTON}>
                <Icon name={status?.kind === 'added' ? 'checkCircle' : 'shoppingCartFill'} className="size-icon-sm lg:size-icon-md" />
            </button>
            {/* Always in the DOM so the live region exists before its message arrives. */}
            <div
                role="status"
                aria-live="polite"
                onMouseEnter={() => setHeld(true)}
                onMouseLeave={() => setHeld(false)}
                onFocus={() => setHeld(true)}
                onBlur={() => setHeld(false)}
                className="pointer-events-none fixed inset-x-4 bottom-[calc(--spacing(20)+env(safe-area-inset-bottom))] z-40 flex justify-center lg:inset-x-auto lg:right-6 lg:bottom-6"
            >
                {status ? (
                    <div
                        className={`pointer-events-auto flex w-full max-w-100 items-center gap-3 rounded-md px-4 py-3 shadow-e3 cart-toast ${
                            status.kind === 'added' ? 'bg-surface-raised text-fg-primary' : 'bg-warning-subtle text-status-low-stock'
                        }`}
                    >
                        <Icon name={status.kind === 'added' ? 'checkCircle' : 'warning'} className={`size-icon-md shrink-0 ${status.kind === 'added' ? 'text-status-in-stock' : ''}`} />
                        <p className="t-body-sm-strong grow">{status.kind === 'added' ? labels.added : status.message}</p>
                        {status.kind === 'added' ? (
                            <a href={cartHref} className="t-body-sm-strong inline-flex min-h-touch-min shrink-0 items-center rounded-xs text-fg-brand underline-offset-2 hover:underline">
                                {labels.viewCart}
                            </a>
                        ) : null}
                        <button
                            type="button"
                            onClick={() => setStatus(null)}
                            aria-label={labels.dismiss}
                            className="relative -mr-1 flex size-8 cursor-pointer shrink-0 items-center justify-center rounded-full text-fg-secondary hover:bg-subtle before:absolute before:size-touch-min before:content-['']"
                        >
                            <Icon name="x" className="size-icon-sm" />
                        </button>
                    </div>
                ) : null}
            </div>
        </form>
    );
}
