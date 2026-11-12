'use client';

import { useRouter } from 'next/navigation';
import { useState, useTransition, type FormEvent, type ReactNode } from 'react';

import { CART_COUNT_EVENT } from '@/components/layout/CartLink';
import type { CartActionResponse } from '@/lib/cart/types';

interface Props {
    /** A cart Route Handler (`/api/cart/...`). */
    action: string;
    className?: string;
    /** Catalog for notice keys (`gone`, `failed`, …); anything else is the API's own message, shown as is. */
    notices: Record<string, string>;
    /** Class of the error line appended after the children (the StockAlert slot). */
    errorClassName?: string;
    /** Server-rendered notice from a no-JS post (the flash cookie), shown until the next submit. */
    initialError?: string | null;
    /** `aria-label` of the form, e.g. the product of a cart line. */
    label?: string;
    children: ReactNode;
}

// Every cart form is a real `<form method="post">` to a Route Handler, so it works without
// JavaScript (303 back to the cart). Once hydrated, a submit is sent with fetch (Accept: JSON), the
// server components re-render in place (router.refresh) and the header count updates — no reload,
// no scroll jump. While it runs the form is `aria-busy` and dimmed (CartLineItem "Updating": 40%).
export function CartForm({ action, className, notices, errorClassName, initialError = null, label, children }: Props) {
    const router = useRouter();
    const [pending, startTransition] = useTransition();
    const [error, setError] = useState<string | null>(initialError);

    async function onSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = event.currentTarget;
        const submitter = (event.nativeEvent as SubmitEvent).submitter;
        const body = new FormData(form, submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement ? submitter : null);
        setError(null);
        startTransition(async () => {
            try {
                const response = await fetch(action, { method: 'POST', body, headers: { Accept: 'application/json' } });
                const result = (await response.json()) as CartActionResponse;
                if (typeof result.itemCount === 'number') window.dispatchEvent(new CustomEvent(CART_COUNT_EVENT, { detail: result.itemCount }));
                if (result.notice?.kind === 'error') setError(notices[result.notice.message] ?? result.notice.message);
            } catch {
                setError(notices.failed ?? null);
            }
            router.refresh();
        });
    }

    return (
        <form method="post" action={action} onSubmit={onSubmit} aria-busy={pending} aria-label={label} data-pending={pending ? '' : undefined} className={className}>
            {children}
            <p role="alert" className={error ? errorClassName : 'sr-only'}>
                {error}
            </p>
        </form>
    );
}
