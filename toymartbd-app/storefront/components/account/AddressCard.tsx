import { Icon } from '@/components/icons/Icon';
import { displayPhone } from '@/lib/account/flash';
import type { AccountMessages } from '@/lib/account/messages';
import type { SavedAddress } from '@/lib/account/types';
import { placeName } from '@/lib/delivery/format';
import { localizedPath, type Locale } from '@/lib/i18n';
import { card } from './styles';

// AddressCard — Figma 236:2384 (Default / Other): type tag (+ "Default"), name · phone, the address,
// the landmark with a pin. Default has the 2px coral border. Edit is a link (the form below fills in);
// Remove and Make default are POST forms, so all of it works without JavaScript.
// The design's per-area delivery charge line needs a parcel to quote, so it is not shown here.

export function AddressCard({ address, locale, m }: { address: SavedAddress; locale: Locale; m: AccountMessages }) {
    const bn = locale === 'bn';
    const place = [address.area, address.upazila ? placeName(address.upazila, bn) : null, placeName(address.district, bn)].filter(Boolean).join(', ');
    const tag = address.label || m.addresses.types[address.type];
    return (
        <article className={`${card} flex flex-col gap-2 ${address.is_default ? 'border-2 border-line-brand' : ''}`}>
            <div className="flex items-center gap-2">
                <span className="t-caption-strong rounded-full bg-tile-pink px-2.5 py-1 text-fg-primary">{tag}</span>
                {address.is_default ? (
                    <span className="t-caption-strong flex items-center gap-1 text-fg-brand">
                        <Icon name="checkCircle" className="size-4" />
                        {m.addresses.default}
                    </span>
                ) : null}
                <a href={`${localizedPath(locale, '/account/addresses')}?edit=${address.id}#address-form`} className="t-body-sm-strong ms-auto flex h-11 items-center text-fg-primary">
                    {m.addresses.edit}
                </a>
            </div>
            <p className="t-body-sm-strong text-fg-primary tabular-nums">
                {address.recipient_name} · {displayPhone(address.phone)}
            </p>
            <p className="t-body-sm text-fg-secondary">
                {address.address_line}, {place}
            </p>
            {address.landmark ? (
                <p className="t-caption flex items-center gap-1.5 text-fg-primary">
                    <Icon name="mapPin" className="size-4 text-fg-brand" />
                    {address.landmark}
                </p>
            ) : null}
            <div className="flex flex-wrap gap-2 pt-1">
                {!address.is_default ? (
                    <form method="post" action="/api/account/addresses">
                        <input type="hidden" name="locale" value={locale} />
                        <input type="hidden" name="intent" value="default" />
                        <input type="hidden" name="id" value={address.id} />
                        <button type="submit" className="t-body-sm-strong h-11 text-fg-link underline">
                            {m.addresses.makeDefault}
                        </button>
                    </form>
                ) : null}
                <form method="post" action="/api/account/addresses">
                    <input type="hidden" name="locale" value={locale} />
                    <input type="hidden" name="intent" value="delete" />
                    <input type="hidden" name="id" value={address.id} />
                    <button type="submit" className="t-body-sm-strong flex h-11 items-center gap-1.5 text-feedback-danger">
                        <Icon name="trash" className="size-4" />
                        {m.addresses.remove}
                    </button>
                </form>
            </div>
        </article>
    );
}
