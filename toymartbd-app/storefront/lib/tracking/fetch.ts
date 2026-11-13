import 'server-only';

import { cached } from '../api';
import { DISABLED_CONFIG, normaliseConfig, type TrackingConfig } from './config';

/** Cache tag Laravel purges when the admin saves the tracking settings (docs/api/revalidation.md). */
export const TRACKING_CONFIG_TAG = 'tracking-config';

/**
 * The public tracking config, ISR-cached under `tracking-config`. Never throws: an unreachable API,
 * a 404 (endpoint not deployed yet) or a malformed body all mean "tracking off" — a page never
 * fails to render because of analytics.
 */
export async function getTrackingConfig(): Promise<TrackingConfig> {
    try {
        return normaliseConfig(await cached<unknown>('storefront/tracking/config', [TRACKING_CONFIG_TAG]));
    } catch {
        return DISABLED_CONFIG;
    }
}
