'use client';

import { useEffect, useState } from 'react';

import { localizedPath, type Locale } from '@/lib/i18n/config';

interface Props {
    locale: Locale;
    labels: { saved: string; removed: string; failed: string };
}

const HEART = 'button[data-wishlist-heart]';

/** The current page as a locale-less path (what sign-in's `?next=` expects). */
function currentPath(): string {
    const path = window.location.pathname.replace(/^\/bn(?=\/|$)/, '');
    return path === '' ? '/' : path;
}

// Enhances every WishlistHeart on the page (storefront spec §A22). Mounted once, in the root layout.
// 1. One GET /api/account/wishlist → the signed-in customer's saved product ids (a guest gets [] and
//    no API call) → those hearts are filled (`aria-pressed`) and their next post is `remove`.
// 2. A heart's form is sent with fetch (Accept: JSON) instead of navigating; a guest (401) goes to
//    sign-in with `?next=` this page. Every heart for the same product (PDP gallery, inline, bar)
//    follows. A second tap while one is in flight is ignored.
// Hearts drawn later (recently viewed rail) are painted as they appear.
export function WishlistHearts({ locale, labels }: Props) {
    const [message, setMessage] = useState('');

    useEffect(() => {
        if (!document.querySelector(HEART)) return;
        const saved = new Set<number>();
        let disposed = false;

        const paint = () => {
            document.querySelectorAll<HTMLButtonElement>(HEART).forEach((button) => {
                const on = saved.has(Number(button.dataset.wishlistHeart));
                if (button.getAttribute('aria-pressed') !== String(on)) button.setAttribute('aria-pressed', String(on));
                button.value = on ? 'remove' : 'add';
            });
        };

        void fetch(`/api/account/wishlist?locale=${locale}`, { headers: { Accept: 'application/json' }, cache: 'no-store' })
            .then((response) => (response.ok ? (response.json() as Promise<unknown>) : null))
            .then((body) => {
                const ids = typeof body === 'object' && body !== null ? (body as { productIds?: unknown }).productIds : null;
                if (disposed || !Array.isArray(ids)) return;
                for (const id of ids) if (typeof id === 'number') saved.add(id);
                paint();
            })
            .catch(() => undefined);

        const onSubmit = (event: SubmitEvent) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-wishlist-form')) return;
            const button = form.querySelector<HTMLButtonElement>(HEART);
            const id = Number(button?.dataset.wishlistHeart);
            if (!button || !Number.isInteger(id)) return;
            event.preventDefault();
            if (form.getAttribute('aria-busy') === 'true') return;
            const add = !saved.has(id);
            const body = new FormData(form);
            body.set('intent', add ? 'add' : 'remove');
            form.setAttribute('aria-busy', 'true');
            setMessage('');
            void (async () => {
                try {
                    const response = await fetch(form.action, { method: 'POST', body, headers: { Accept: 'application/json' } });
                    if (response.status === 401) {
                        window.location.assign(`${localizedPath(locale, '/account/login')}?next=${encodeURIComponent(currentPath())}`);
                        return;
                    }
                    const result = (await response.json().catch(() => null)) as { ok?: unknown } | null;
                    if (!response.ok || result?.ok !== true) {
                        setMessage(labels.failed);
                        return;
                    }
                    if (add) saved.add(id);
                    else saved.delete(id);
                    paint();
                    setMessage(add ? labels.saved : labels.removed);
                } catch {
                    setMessage(labels.failed);
                } finally {
                    form.removeAttribute('aria-busy');
                }
            })();
        };

        const observer = new MutationObserver(paint);
        observer.observe(document.body, { childList: true, subtree: true });
        document.addEventListener('submit', onSubmit);
        return () => {
            disposed = true;
            observer.disconnect();
            document.removeEventListener('submit', onSubmit);
        };
    }, [locale, labels]);

    return (
        <p role="status" aria-live="polite" className="sr-only">
            {message}
        </p>
    );
}
