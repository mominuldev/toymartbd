'use client';

import Script from 'next/script';
import { useCallback, useEffect, useRef, useState } from 'react';

import type { Messages } from '@/lib/i18n';
import { configureTracking, scriptPlan } from '@/lib/tracking/browser';
import { trackingActive, type TrackingConfig } from '@/lib/tracking/config';
import { CONSENT_COOKIE, consentCookieString, effectiveConsent, parseConsent, type EffectiveConsent } from '@/lib/tracking/consent';
import { cookieValue } from '@/lib/tracking/identifiers';
import { ConsentBanner } from './ConsentBanner';

/** Dispatched by the footer's "Cookie settings" link (CookieSettingsLink). */
export const CONSENT_OPEN_EVENT = 'tm:consent-open';
/** Set on <html> once the banner can be reopened, so the footer link knows to stay on the page. */
export const CONSENT_READY_ATTR = 'data-consent-ready';

interface Props {
    config: TrackingConfig;
    labels: Messages['consent'];
    privacyHref: string;
    bangla: boolean;
}

function gpc(): boolean {
    return (navigator as Navigator & { globalPrivacyControl?: boolean }).globalPrivacyControl === true;
}

// Consent + tag loading for every page (rendered once, in the root layout). Client-only by design:
// pages are ISR-cached and identical for every visitor, so the consent cookie is read after
// hydration. Without JavaScript nothing renders and nothing loads — there is no tag to consent to.
//
// Third-party scripts are next/script `lazyOnload` (after the load event, in idle time — never in
// the critical path, UI-UX spec §7) and are rendered ONLY once the visitor's consent covers them
// (lib/tracking/browser.ts scriptPlan). In opt_in mode with no answer yet, this renders the banner
// and no <script> at all.
export function TrackingRoot({ config, labels, privacyHref, bangla }: Props) {
    const active = trackingActive(config);
    const [consent, setConsent] = useState<EffectiveConsent | null>(null);
    const [open, setOpen] = useState(false);
    const [saved, setSaved] = useState(false);
    const [reopened, setReopened] = useState(false);
    /** Where focus goes when a reopened banner closes. */
    const opener = useRef<HTMLElement | null>(null);

    useEffect(() => {
        const current = effectiveConsent(parseConsent(cookieValue(document.cookie, CONSENT_COOKIE)), config, gpc());
        setConsent(current);
        configureTracking(config, current);
        if (!active) return;
        if (!current.decided) setOpen(true);
        document.documentElement.setAttribute(CONSENT_READY_ATTR, '');
        const reopen = () => {
            opener.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            setSaved(false);
            setReopened(true);
            setOpen(true);
        };
        window.addEventListener(CONSENT_OPEN_EVENT, reopen);
        return () => {
            window.removeEventListener(CONSENT_OPEN_EVENT, reopen);
            document.documentElement.removeAttribute(CONSENT_READY_ATTR);
        };
    }, [config, active]);

    const decide = useCallback(
        (analytics: boolean, ads: boolean) => {
            document.cookie = consentCookieString({ policyVersion: config.policyVersion, analytics, ads, decidedAt: Date.now() / 1000 }, window.location.protocol === 'https:');
            const next: EffectiveConsent = { analytics, ads, mode: config.consentMode, policyVersion: config.policyVersion, decided: true };
            setConsent(next);
            configureTracking(config, next);
            setOpen(false);
            setReopened(false);
            setSaved(true);
            // Focus back to whatever opened the banner (footer link), else to the page's main region.
            const target = opener.current ?? document.getElementById('main');
            opener.current = null;
            target?.focus({ preventScroll: true });
        },
        [config],
    );

    const close = useCallback(() => {
        setOpen(false);
        setReopened(false);
        const target = opener.current;
        opener.current = null;
        target?.focus({ preventScroll: true });
    }, []);

    if (!active || !consent) return null;
    const plan = scriptPlan(config, consent);

    return (
        <>
            {open ? (
                <ConsentBanner
                    labels={labels}
                    privacyHref={privacyHref}
                    bangla={bangla}
                    mode={config.consentMode}
                    initial={{ analytics: consent.analytics, ads: consent.ads }}
                    // A banner the visitor reopened can be dismissed without changing anything.
                    dismissible={consent.decided}
                    reopened={reopened}
                    onDecide={decide}
                    onClose={close}
                />
            ) : null}
            <p role="status" aria-live="polite" className="sr-only">
                {saved ? labels.saved : ''}
            </p>
            {plan.gtm ? <Script id="tm-gtm" strategy="lazyOnload" src={`https://www.googletagmanager.com/gtm.js?id=${plan.gtm}`} /> : null}
            {plan.gtag ? <Script id="tm-gtag" strategy="lazyOnload" src={`https://www.googletagmanager.com/gtag/js?id=${plan.gtag}`} /> : null}
            {plan.pixel ? <Script id="tm-pixel" strategy="lazyOnload" src="https://connect.facebook.net/en_US/fbevents.js" /> : null}
        </>
    );
}
