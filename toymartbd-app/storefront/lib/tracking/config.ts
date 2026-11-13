// Public tracking configuration (K-01/K-02): `GET /api/v1/storefront/tracking/config`, read by a
// Server Component (lib/tracking/fetch.ts) and handed to the browser. Only PUBLIC ids and flags —
// the GA4 API secret and the Meta access token never leave Laravel (CLAUDE.md rule 28).
//
// Client-safe: no `server-only`, no Node APIs. Every id is shape-checked here because it ends up in
// a script URL; anything unexpected is dropped rather than injected.

import type { Schema } from '@toymart/api-types';

/** The API's JSON (StorefrontTrackingConfig, docs/api/tracking.md). */
export type TrackingConfigResponse = Schema<'StorefrontTrackingConfig'>;
export type ConsentMode = TrackingConfigResponse['consent_mode'];
export type PurchaseTrigger = TrackingConfigResponse['purchase_trigger'];

/** Normalised: ids that failed their shape check are null, `active` says whether anything can run. */
export interface TrackingConfig {
    ga4MeasurementId: string | null;
    metaPixelId: string | null;
    gtmContainerId: string | null;
    consentMode: ConsentMode;
    policyVersion: string;
    purchaseTrigger: PurchaseTrigger;
    enabled: { ga4: boolean; meta: boolean };
}

const GA4_ID = /^G-[A-Z0-9]{4,20}$/;
const PIXEL_ID = /^\d{5,20}$/;
const GTM_ID = /^GTM-[A-Z0-9]{4,12}$/;
/** Same alphabet the consent cookie accepts (lib/tracking/consent.ts). */
export const POLICY_VERSION = /^[A-Za-z0-9._-]{1,40}$/;

/** What the storefront runs with when the API is unreachable or tracking is not set up: nothing. */
export const DISABLED_CONFIG: TrackingConfig = {
    ga4MeasurementId: null,
    metaPixelId: null,
    gtmContainerId: null,
    consentMode: 'opt_in',
    policyVersion: '0',
    purchaseTrigger: 'order_placed',
    enabled: { ga4: false, meta: false },
};

function pick(value: unknown, pattern: RegExp): string | null {
    return typeof value === 'string' && pattern.test(value.trim()) ? value.trim() : null;
}

/** Accepts the bare object or a `{data: …}` wrapper (Laravel API Resource default). */
export function normaliseConfig(raw: unknown): TrackingConfig {
    const body = raw && typeof raw === 'object' && 'data' in raw && raw.data && typeof raw.data === 'object' ? raw.data : raw;
    if (!body || typeof body !== 'object') return DISABLED_CONFIG;
    const r = body as Partial<Record<keyof TrackingConfigResponse, unknown>>;
    const enabled = (r.enabled && typeof r.enabled === 'object' ? r.enabled : {}) as { ga4?: unknown; meta?: unknown };
    const ga4MeasurementId = pick(r.ga4_measurement_id, GA4_ID);
    const metaPixelId = pick(r.meta_pixel_id, PIXEL_ID);
    return {
        ga4MeasurementId,
        metaPixelId,
        gtmContainerId: pick(r.gtm_container_id, GTM_ID),
        // Anything but an explicit `notice` is the strict default (opt-in, §7.10 rows 3–4).
        consentMode: r.consent_mode === 'notice' ? 'notice' : 'opt_in',
        policyVersion: pick(r.consent_policy_version, POLICY_VERSION) ?? DISABLED_CONFIG.policyVersion,
        purchaseTrigger: r.purchase_trigger === 'order_delivered' ? 'order_delivered' : 'order_placed',
        enabled: { ga4: enabled.ga4 === true && ga4MeasurementId !== null, meta: enabled.meta === true && metaPixelId !== null },
    };
}

/**
 * Anything to ask consent for: a destination is enabled, or a GTM container is set. When nothing
 * is configured the banner stays hidden — there is nothing to consent to.
 */
export function trackingActive(config: TrackingConfig): boolean {
    return config.enabled.ga4 || config.enabled.meta || config.gtmContainerId !== null;
}
