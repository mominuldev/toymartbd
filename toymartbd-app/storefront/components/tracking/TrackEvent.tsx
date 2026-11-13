'use client';

import { useEffect, useRef } from 'react';

import { trackEvent, trackPurchase, type EventData, type PurchaseData } from '@/lib/tracking/browser';
import type { BrowserEventName } from '@/lib/tracking/types';

/**
 * Run `fire` once the document is really shown: a speculation-rules PRERENDER runs effects too, so
 * wait for `prerenderingchange`. (A Next <Link> prefetch only fetches the RSC payload — it mounts
 * nothing and runs no effect.)
 */
export function whenShown(fire: () => void): () => void {
    const doc = document as Document & { prerendering?: boolean };
    if (!doc.prerendering) {
        fire();
        return () => undefined;
    }
    const onShown = () => fire();
    document.addEventListener('prerenderingchange', onShown, { once: true });
    return () => document.removeEventListener('prerenderingchange', onShown);
}

interface Props {
    name: BrowserEventName;
    /** Identity of what is being reported (product slug + variant, cart token-free key): a new value fires again. */
    dedupeKey: string;
    data: EventData;
}

// Fires one funnel event (view_item on the PDP, begin_checkout on checkout) per mount and key.
// The ref survives React StrictMode's dev double-effect and every re-render (router.refresh on
// checkout re-renders in place), so it is never sent twice for the same page view.
export function TrackEvent({ name, dedupeKey, data }: Props) {
    const fired = useRef<string | null>(null);

    useEffect(() => {
        if (fired.current === dedupeKey) return;
        return whenShown(() => {
            if (fired.current === dedupeKey) return;
            fired.current = dedupeKey;
            trackEvent(name, data);
        });
    }, [name, dedupeKey, data]);

    return null;
}

interface PurchaseProps {
    eventId: string;
    fireBrowserPurchase: boolean;
    data: PurchaseData;
}

/** The order confirmation's browser Purchase (Meta Pixel only; see lib/tracking/browser.ts trackPurchase). */
export function TrackPurchase({ eventId, fireBrowserPurchase, data }: PurchaseProps) {
    useEffect(() => {
        if (!fireBrowserPurchase) return;
        // trackPurchase itself remembers the event id (memory + localStorage): StrictMode-safe.
        return whenShown(() => trackPurchase(eventId, true, data));
    }, [eventId, fireBrowserPurchase, data]);

    return null;
}
