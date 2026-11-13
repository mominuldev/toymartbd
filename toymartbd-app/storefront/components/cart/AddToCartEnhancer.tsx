'use client';

import { useEffect, useRef, useState } from 'react';

import { Icon } from '@/components/icons/Icon';
import { CART_COUNT_EVENT } from '@/components/layout/CartLink';
import type { CartActionResponse } from '@/lib/cart/types';
import { trackEvent } from '@/lib/tracking/browser';
import { TRACKING_CURRENCY } from '@/lib/tracking/items';
import type { TrackingItem } from '@/lib/tracking/types';

interface Props {
    formId: string;
    cartHref: string;
    labels: { added: string; viewCart: string; failed: string; notices: Record<string, string> };
    /** The variant being added (K-01 add_to_cart); its quantity comes from the submitted form. */
    trackingItem?: TrackingItem;
}

type Status = { kind: 'added' } | { kind: 'error'; message: string } | null;

// Progressive enhancement for the PDP cart form (P4-STO-08). Without JavaScript the form POSTs
// and the Route Handler 303s to the cart. Once hydrated, "Add to cart" is sent with fetch
// (Accept: JSON): the shopper stays on the product, the header count updates and a polite status
// says it worked (or the API's own reason, e.g. "Only 3 left in stock."). "Buy now" and "Notify
// me" still navigate normally. A second tap while one add is in flight is ignored, not doubled.
export function AddToCartEnhancer({ formId, cartHref, labels, trackingItem }: Props) {
    const [status, setStatus] = useState<Status>(null);
    const busy = useRef(false);

    useEffect(() => {
        const form = document.getElementById(formId);
        if (!(form instanceof HTMLFormElement)) return;
        const onSubmit = (event: SubmitEvent) => {
            const submitter = event.submitter;
            const intent = submitter instanceof HTMLButtonElement ? submitter.value : 'add';
            if (intent !== 'add') return;
            event.preventDefault();
            if (busy.current) return;
            busy.current = true;
            form.setAttribute('aria-busy', 'true');
            const body = new FormData(form, submitter);
            setStatus(null);
            void (async () => {
                try {
                    const response = await fetch(form.action, { method: 'POST', body, headers: { Accept: 'application/json' } });
                    const result = (await response.json()) as CartActionResponse;
                    if (typeof result.itemCount === 'number') window.dispatchEvent(new CustomEvent(CART_COUNT_EVENT, { detail: result.itemCount }));
                    if (result.ok) {
                        setStatus({ kind: 'added' });
                        // Only a confirmed add is reported (consent-gated inside trackEvent). No value:
                        // price × quantity would be money computed in the client (rule 1).
                        const quantity = Number(body.get('quantity') ?? 1);
                        if (trackingItem) trackEvent('add_to_cart', { currency: TRACKING_CURRENCY, items: [{ ...trackingItem, quantity: Number.isInteger(quantity) && quantity >= 1 ? quantity : 1 }] });
                    }
                    else {
                        const message = result.notice ? (labels.notices[result.notice.message] ?? result.notice.message) : labels.notices.failed;
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
        form.addEventListener('submit', onSubmit);
        return () => form.removeEventListener('submit', onSubmit);
    }, [formId, labels, trackingItem]);

    return (
        <div role="status" aria-live="polite" className="order-6 empty:hidden lg:order-none lg:col-span-3">
            {status?.kind === 'added' ? (
                <p className="t-body-sm-strong flex items-center gap-2 rounded-xs bg-success-subtle px-3 py-2 text-fg-primary">
                    <Icon name="checkCircle" className="size-icon-md text-status-in-stock" />
                    <span className="grow">{labels.added}</span>
                    <a href={cartHref} className="text-fg-brand underline-offset-2 hover:underline">
                        {labels.viewCart}
                    </a>
                </p>
            ) : status?.kind === 'error' ? (
                <p className="t-body-sm-strong flex items-center gap-2 rounded-xs bg-warning-subtle px-3 py-2 text-status-low-stock">
                    <Icon name="warning" className="size-icon-md shrink-0" />
                    {status.message}
                </p>
            ) : null}
        </div>
    );
}
