import { Icon } from '@/components/icons/Icon';
import type { AccountMessages } from '@/lib/account/messages';
import { isZero } from '@/lib/cart/format';
import type { StorefrontOrder } from '@/lib/cart/types';
import { placeName } from '@/lib/delivery/format';
import { format, type Locale, type Messages } from '@/lib/i18n';
import { formatPrice } from '@/lib/listing/format';

// The delivery address on the account order page, drawn as the AddressCard (Figma 236:2384 inside
// 237:10066 / 239:12698): 2px coral border, name · phone, the address, the landmark with a pin and a
// mint "Delivery ৳70" chip with the charge the API billed on this order (rule 1 — never recomputed).
// The design's tag and Edit button belong to a saved address; an order's address is fixed once placed.
export function OrderAddressCard({ order, locale, t, m }: { order: StorefrontOrder; locale: Locale; t: Messages; m: AccountMessages }) {
    const s = order.shipping;
    const bn = locale === 'bn';
    const places = [s.area, s.upazila ? placeName(s.upazila, bn) : null, s.district ? placeName(s.district, bn) : null].filter(Boolean).join(', ');
    const charge = order.totals.shipping_charge;
    return (
        <section aria-labelledby="order-address" className="flex flex-col gap-2 rounded-sm border-2 border-line-brand bg-surface p-4">
            <h2 id="order-address" className="t-caption-strong flex items-center gap-1.5 text-fg-brand">
                <Icon name="mapPin" className="size-3.5" />
                {t.order.deliverTo}
            </h2>
            <p className="t-body-sm-strong text-fg-primary tabular-nums">
                {s.name} · {s.phone_masked}
            </p>
            <p className="t-body-sm text-fg-secondary">
                {[s.address_line, places].filter(Boolean).join(', ')}
                {s.postcode ? ` ${s.postcode}` : ''}
            </p>
            {s.landmark ? (
                <p className="t-caption-strong flex items-center gap-1.5 text-fg-primary">
                    <Icon name="mapPin" className="size-4 shrink-0 text-fg-brand" />
                    {s.landmark}
                </p>
            ) : null}
            <p className="t-caption flex w-fit items-center gap-1.5 rounded-xs bg-success-subtle px-2.5 py-1.5 text-fg-primary tabular-nums">
                <Icon name="truck" className="size-3.5" />
                {isZero(charge) ? format(m.orders.deliveryCharge, { amount: t.cart.free }) : format(m.orders.deliveryCharge, { amount: formatPrice(charge) })}
            </p>
        </section>
    );
}
