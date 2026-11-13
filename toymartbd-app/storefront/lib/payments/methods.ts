import 'server-only';

import { noStore } from '../api';
import type { Locale } from '../i18n/config';

// Which payment methods checkout may offer (P4-PAY-02, docs/api/checkout.md "Online payment").
// COD is always offered and is the default (rule 33); an online method appears only while the API
// reports its gateway enabled — active, configured and, in production, verified. Never cached: an
// owner can switch a gateway off at any moment. On any failure we offer COD only.

export interface PaymentMethodOption {
    method: string;
    online: boolean;
    default: boolean;
    sandbox: boolean;
}

export const COD_ONLY: PaymentMethodOption[] = [{ method: 'cod', online: false, default: true, sandbox: false }];

/** The online methods this storefront knows how to send a customer to. */
export const ONLINE_METHODS = ['sslcommerz'] as const;
export type OnlineMethod = (typeof ONLINE_METHODS)[number];

export function isOnlineMethod(value: unknown): value is OnlineMethod {
    return typeof value === 'string' && (ONLINE_METHODS as readonly string[]).includes(value);
}

export async function getPaymentMethods(locale: Locale): Promise<PaymentMethodOption[]> {
    try {
        const { data } = await noStore<{ data: PaymentMethodOption[] }>('storefront/payment-methods', { locale });
        const known = data.filter((m) => m.method === 'cod' || isOnlineMethod(m.method));
        return known.some((m) => m.method === 'cod') ? known : COD_ONLY;
    } catch {
        return COD_ONLY;
    }
}
