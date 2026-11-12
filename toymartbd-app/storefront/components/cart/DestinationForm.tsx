import { buttonClass } from '@/components/layout/button-styles';
import type { Destination } from '@/lib/cart/server';
import { placeName } from '@/lib/delivery/format';
import type { DeliveryReference } from '@/lib/delivery/types';
import type { Locale, Messages } from '@/lib/i18n';
import { SelectBox } from './fields';

interface Props {
    reference: DeliveryReference;
    dest: Destination | null;
    locale: Locale;
    t: Messages;
}

// "Delivery to" on the cart (not in the Figma cart, which assumes a known district): the district
// (+ upazila where the charge depends on it) whose delivery charge the API adds to the totals —
// GET /cart?district_id=&upazila_id= (cart.md). A plain POST that stores the choice in a cookie and
// comes back, so the total is the API's for that place with or without JavaScript. Checkout asks
// for the full address anyway; this is only so the cart total is not missing its delivery line.
export function DestinationForm({ reference, dest, locale, t }: Props) {
    const c = t.cart;
    const bn = locale === 'bn';
    const district = dest ? (reference.districts.find((d) => d.id === dest.districtId) ?? null) : null;
    const upazilas = district?.upazila_required ? (reference.upazilas[district.id] ?? []) : [];
    if (reference.districts.length === 0) return null;

    return (
        <details open={!dest} className="group rounded-sm border border-line-default bg-surface">
            <summary className="t-body-sm-strong flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 px-4 py-2 text-fg-primary [&::-webkit-details-marker]:hidden">
                <span>
                    {c.destinationTitle}
                    {district ? <span className="font-normal text-fg-secondary"> · {placeName(district, bn)}</span> : null}
                </span>
                <span className="text-fg-brand">{t.delivery.change}</span>
            </summary>
            <form method="post" action="/api/cart/destination" className="flex flex-col gap-3 px-4 pb-4">
                <input type="hidden" name="locale" value={locale} />
                <p className="t-caption text-fg-secondary">{c.destinationHelp}</p>
                <label className="flex flex-col gap-1">
                    <span className="t-field-label text-fg-primary">{t.checkout.district}</span>
                    <SelectBox name="district_id" defaultValue={district ? String(district.id) : ''}>
                        <option value="">{t.checkout.districtPlaceholder}</option>
                        {reference.districts.map((d) => (
                            <option key={d.id} value={d.id}>
                                {placeName(d, bn)}
                            </option>
                        ))}
                    </SelectBox>
                </label>
                {upazilas.length > 0 ? (
                    <label className="flex flex-col gap-1">
                        <span className="t-field-label text-fg-primary">{t.checkout.upazila}</span>
                        <SelectBox name="upazila_id" defaultValue={dest?.upazilaId ? String(dest.upazilaId) : ''}>
                            <option value="">{district?.city_option ? `${placeName(district, bn)}` : t.checkout.upazilaPlaceholder}</option>
                            {upazilas.map((u) => (
                                <option key={u.id} value={u.id} disabled={!u.serviceable}>
                                    {placeName(u, bn)}
                                </option>
                            ))}
                        </SelectBox>
                    </label>
                ) : null}
                <button type="submit" className={`${buttonClass('secondary', 'md')} w-full`}>
                    {c.destinationSave}
                </button>
            </form>
        </details>
    );
}
