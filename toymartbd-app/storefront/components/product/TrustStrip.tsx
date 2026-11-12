import { Icon } from '@/components/icons/Icon';
import type { IconName } from '@/components/icons/paths';
import { format, type Messages } from '@/lib/i18n';
import { contact } from '@/lib/site-config';

// TrustStrip — Figma 117:2678: Layout=Grid2x2 at 390 (128:1028, two columns, icon beside the text)
// and Row4 at 1440 (135:1515, four columns, icon above). Brand-subtle panel, white icon bubbles,
// coral icons. The hotline is the one site-config value (a placeholder until the owner confirms).
export function TrustStrip({ t }: { t: Messages }) {
    const items: Array<{ icon: IconName; label: string; detail: string }> = [
        { icon: 'money', label: t.pdp.trustCod, detail: t.pdp.trustCodDetail },
        { icon: 'arrowCounterClockwise', label: t.pdp.trustReturn, detail: t.pdp.trustReturnDetail },
        { icon: 'shieldCheck', label: t.pdp.trustAuthentic, detail: t.pdp.trustAuthenticDetail },
        { icon: 'phone', label: format(t.pdp.trustCall, { phone: contact.hotline.display }), detail: t.pdp.trustCallDetail },
    ];
    return (
        <ul className="grid grid-cols-2 gap-4 rounded-sm bg-brand-subtle p-4 lg:grid-cols-4">
            {items.map((item) => (
                <li key={item.icon} className="flex min-w-0 items-center gap-3 lg:flex-col lg:items-start lg:gap-2">
                    <span aria-hidden="true" className="flex size-9 shrink-0 items-center justify-center rounded-full bg-surface text-fg-brand">
                        <Icon name={item.icon} className="size-icon-md" />
                    </span>
                    <span className="flex min-w-0 flex-col">
                        <span className="t-body-sm-strong text-fg-primary">{item.label}</span>
                        <span className="t-caption text-fg-tertiary">{item.detail}</span>
                    </span>
                </li>
            ))}
        </ul>
    );
}
