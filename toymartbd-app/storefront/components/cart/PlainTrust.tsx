import { Icon } from '@/components/icons/Icon';
import type { IconName } from '@/components/icons/paths';
import { format, type Messages } from '@/lib/i18n';
import { contact } from '@/lib/site-config';

// TrustStrip Layout=Plain (Figma 117:2678, cart 130:2302 / checkout 131:2601 rails): a hairline
// rule above a 2 × 2 of 52-tall cells — 36 bubble with a secondary glyph, one semibold line.
export function PlainTrust({ t }: { t: Messages }) {
    const items: Array<{ icon: IconName; label: string }> = [
        { icon: 'money', label: t.pdp.trustCod },
        { icon: 'arrowCounterClockwise', label: t.pdp.trustReturn },
        { icon: 'shieldCheck', label: t.pdp.trustAuthentic },
        { icon: 'phone', label: format(t.pdp.trustCall, { phone: contact.hotline.display }) },
    ];
    return (
        <ul className="grid grid-cols-2 gap-4 border-t border-line-default py-4">
            {items.map((item) => (
                <li key={item.icon} className="flex h-13 min-w-0 items-center gap-2.5">
                    <span aria-hidden="true" className="flex size-9 shrink-0 items-center justify-center rounded-full text-fg-secondary">
                        <Icon name={item.icon} className="size-icon-md" />
                    </span>
                    <span className="t-body-sm-strong min-w-0 text-fg-secondary">{item.label}</span>
                </li>
            ))}
        </ul>
    );
}
