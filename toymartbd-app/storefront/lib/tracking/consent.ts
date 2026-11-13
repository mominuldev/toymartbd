import { POLICY_VERSION, type ConsentMode, type TrackingConfig } from './config';

// Consent state (BD Digital Commerce Operation Guidelines — agent4 research §7.10 rows 3–4: cookie
// and tracking disclosure beforehand, prior consent). Client-safe and pure; the browser writes the
// cookie (components/tracking), Route Handlers read it from the request.
//
// Cookie `tm_consent`, first-party, not httpOnly (the banner reads it), SameSite=Lax, 180 days:
//
//     1|<policy version>|<analytics 0/1>|<ads 0/1>|<decided at, unix seconds>
//
// Analytics (GA4, GTM) and ads (Meta Pixel) are separate choices. A cookie written under another
// `consent_policy_version` counts as NO decision: the banner asks again, and until it is answered
// the mode's default applies.
//
// Modes (Admin → Tracking → consent mode, `consent_mode` in the public config):
//   opt_in (default) — before a decision both are DENIED: no tracking script is added to the page,
//                       no event is fired and nothing is POSTed to /api/events. The banner asks.
//   notice          — both are GRANTED by default and the banner is a notice with the same Reject /
//                       Choose controls; an explicit choice always wins. A browser that sends Global
//                       Privacy Control (`navigator.globalPrivacyControl` / `Sec-GPC: 1`) is treated
//                       as having rejected, so notice mode never overrides a standing opt-out.

export const CONSENT_COOKIE = 'tm_consent';
export const CONSENT_MAX_AGE = 180 * 24 * 60 * 60;
const FORMAT = '1';

export interface StoredConsent {
    policyVersion: string;
    analytics: boolean;
    ads: boolean;
    /** Unix seconds. */
    decidedAt: number;
}

export interface EffectiveConsent {
    analytics: boolean;
    ads: boolean;
    mode: ConsentMode;
    policyVersion: string;
    /** The visitor answered under the current policy version (the banner stays closed). */
    decided: boolean;
}

/** The shape the API takes (`consent` in tracking events and checkout's `tracking`). */
export interface ConsentPayload {
    analytics: boolean;
    ads: boolean;
    mode: ConsentMode;
    policy_version: string;
}

export function serializeConsent(consent: StoredConsent): string {
    return [FORMAT, consent.policyVersion, consent.analytics ? '1' : '0', consent.ads ? '1' : '0', String(Math.floor(consent.decidedAt))].join('|');
}

/** Null for a missing, malformed or foreign-format value — never a guess. */
export function parseConsent(raw: string | null | undefined): StoredConsent | null {
    if (!raw) return null;
    let value = raw;
    try {
        value = decodeURIComponent(raw);
    } catch {
        return null;
    }
    const parts = value.split('|');
    if (parts.length !== 5) return null;
    const [format, policyVersion, analytics, ads, decidedAt] = parts as [string, string, string, string, string];
    if (format !== FORMAT || !POLICY_VERSION.test(policyVersion)) return null;
    if (!/^[01]$/.test(analytics) || !/^[01]$/.test(ads) || !/^\d{1,12}$/.test(decidedAt)) return null;
    return { policyVersion, analytics: analytics === '1', ads: ads === '1', decidedAt: Number(decidedAt) };
}

/** What may run right now, given the stored answer, the shop's mode and the browser's GPC signal. */
export function effectiveConsent(stored: StoredConsent | null, config: Pick<TrackingConfig, 'consentMode' | 'policyVersion'>, gpc = false): EffectiveConsent {
    const base = { mode: config.consentMode, policyVersion: config.policyVersion };
    if (stored && stored.policyVersion === config.policyVersion) {
        return { ...base, analytics: stored.analytics, ads: stored.ads, decided: true };
    }
    const byDefault = config.consentMode === 'notice' && !gpc;
    return { ...base, analytics: byDefault, ads: byDefault, decided: false };
}

export function consentPayload(consent: EffectiveConsent): ConsentPayload {
    return { analytics: consent.analytics, ads: consent.ads, mode: consent.mode, policy_version: consent.policyVersion };
}

/** `Set-Cookie`-style attribute string for document.cookie. Secure on https only (local dev is http). */
export function consentCookieString(consent: StoredConsent, secure: boolean): string {
    return `${CONSENT_COOKIE}=${encodeURIComponent(serializeConsent(consent))}; Path=/; Max-Age=${CONSENT_MAX_AGE}; SameSite=Lax${secure ? '; Secure' : ''}`;
}

export type GoogleConsentValue = 'granted' | 'denied';
export interface GoogleConsentState {
    ad_storage: GoogleConsentValue;
    ad_user_data: GoogleConsentValue;
    ad_personalization: GoogleConsentValue;
    analytics_storage: GoogleConsentValue;
}

/**
 * Google Consent Mode v2 (https://developers.google.com/tag-platform/security/guides/consent):
 * the `default` command runs before gtag.js / GTM loads, everything denied; the `update` then
 * carries the visitor's choice. Ads consent covers the three ad signals together.
 */
export const GOOGLE_CONSENT_DEFAULT: GoogleConsentState = {
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    analytics_storage: 'denied',
};

export function googleConsent(consent: Pick<EffectiveConsent, 'analytics' | 'ads'>): GoogleConsentState {
    const ads: GoogleConsentValue = consent.ads ? 'granted' : 'denied';
    return { ad_storage: ads, ad_user_data: ads, ad_personalization: ads, analytics_storage: consent.analytics ? 'granted' : 'denied' };
}
