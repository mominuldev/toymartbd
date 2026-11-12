import { getDeliveryReference } from '@/lib/delivery/fetch';
import type { DeliveryQuote, DeliverySelection } from '@/lib/delivery/types';
import { getMessages, localizedPath, type Locale } from '@/lib/i18n';

import { DeliveryEstimatorClient } from './DeliveryEstimatorClient';

// DeliveryEstimator — Figma 119:1329 (states Empty 119:1195, Selected 119:1221, SelectedFree
// 119:1246, NotServiced 119:1266, Loading 119:1317; PDP 1440 instance 135:1483), storefront spec
// §A12. The real delivery charge and an absolute arrival date on the product page, from the API's
// DeliveryChargeResolver — the code path checkout uses (P4-CRT-03), so the two cannot disagree.
// The storefront computes nothing (rule 1): charge, dates and the free-delivery meter are the API's.
//
// This server part loads the cached reference data (rates for the published fallback line,
// districts, the upazilas of districts whose charge depends on one) and server-renders the
// estimator, so the page keeps its ISR cache: no cookie or per-visitor read happens here.
//
// Without JavaScript it is a plain GET form to /delivery-charge, which renders the quote server
// side. With JavaScript, choosing a district fetches the quote in place through /api/delivery-quote
// and remembers the choice for the next product page. Every state reserves min-height 132 (140 on
// desktop) so nothing below it moves.

export interface DeliveryEstimatorProps {
    locale: Locale;
    variantId: number;
    quantity?: number;
    /** Server-rendered result (the /delivery-charge page): skips the client fetch. */
    initial?: { selection: DeliverySelection; quote: DeliveryQuote | null };
}

export async function DeliveryEstimator({ locale, variantId, quantity = 1, initial }: DeliveryEstimatorProps) {
    const reference = await getDeliveryReference(locale);

    return (
        <DeliveryEstimatorClient
            locale={locale}
            t={getMessages(locale).delivery}
            reference={reference}
            variantId={variantId > 0 ? variantId : null}
            quantity={quantity}
            action={localizedPath(locale, '/delivery-charge')}
            initial={initial ?? null}
        />
    );
}
