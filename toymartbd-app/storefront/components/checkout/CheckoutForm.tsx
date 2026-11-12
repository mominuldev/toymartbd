'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';

export const CHECKOUT_FORM_ID = 'checkout-form';

interface Props {
    children: ReactNode;
}

interface Answer {
    redirect?: string;
    refresh?: boolean;
}

// The checkout <form> (P4-STO-09). Without JavaScript it is a plain POST to /api/checkout and every
// answer is a 303. Once hydrated:
//  • a submit goes by fetch (Accept: JSON); success navigates to the confirmation, anything else
//    re-renders the page in place (router.refresh) — the server renders the API's errors, the new
//    total after a price change, the coupon — while what the customer typed stays in the inputs;
//  • changing the district or upazila re-prices at once (intent=refresh), so the delivery charge,
//    the total and the Place order label are always the API's for the address on screen;
//  • while an order is being placed the Place order buttons are disabled and say so. The
//    Idempotency-Key in the form makes a double submit return the same order anyway.
export function CheckoutForm({ children }: Props) {
    const router = useRouter();
    const form = useRef<HTMLFormElement>(null);
    const [placing, setPlacing] = useState(false);
    const busy = useRef(false);
    const [hydrated, setHydrated] = useState(false);

    useEffect(() => setHydrated(true), []);

    // The Place order buttons live in the form and in the rail (`form=` attribute): disable them all.
    useEffect(() => {
        document.querySelectorAll<HTMLButtonElement>(`button[name="intent"][value="place"]`).forEach((button) => {
            button.disabled = placing;
            button.setAttribute('aria-busy', String(placing));
        });
    }, [placing]);

    /** Resolves true when the page is navigating away (stay busy until it does). */
    async function post(body: FormData): Promise<boolean> {
        const response = await fetch('/api/checkout', { method: 'POST', body, headers: { Accept: 'application/json' } });
        const answer = (await response.json().catch(() => ({ refresh: true }))) as Answer;
        if (answer.redirect) {
            window.location.assign(answer.redirect);
            return true;
        }
        router.refresh();
        return false;
    }

    function onSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (busy.current) return; // a second tap while the first is running
        const submitter = (event.nativeEvent as SubmitEvent).submitter;
        const body = new FormData(event.currentTarget, submitter instanceof HTMLButtonElement ? submitter : null);
        const isPlace = (body.get('intent') ?? 'place') === 'place';
        busy.current = true;
        if (isPlace) setPlacing(true);
        post(body)
            .catch(() => {
                // Network failure placing: fall back to the no-JS path with the same key (safe to repeat).
                if (!isPlace) return false;
                form.current?.submit();
                return true;
            })
            .then((leaving) => {
                if (leaving) return;
                busy.current = false;
                setPlacing(false);
            });
    }

    function onChange(event: FormEvent<HTMLFormElement>) {
        const target = event.target;
        if (!(target instanceof HTMLSelectElement) || (target.name !== 'district_id' && target.name !== 'upazila_id')) return;
        const body = new FormData(event.currentTarget);
        if (target.name === 'district_id') body.delete('upazila_id'); // the old upazila belongs to the old district
        body.set('intent', 'refresh');
        void post(body);
    }

    return (
        <form
            ref={form}
            id={CHECKOUT_FORM_ID}
            method="post"
            action="/api/checkout"
            onSubmit={onSubmit}
            onChange={onChange}
            aria-busy={placing}
            data-js={hydrated ? '' : undefined}
            className="group/checkout flex flex-col gap-5"
        >
            {children}
        </form>
    );
}
