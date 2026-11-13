import type { ReactNode } from 'react';
import { ChatTextIcon, EnvelopeSimpleIcon, WhatsappLogoIcon, type Icon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { en as enMessages, type MessageKey } from '@/i18n/messages';
import { StatusChip, type StatusTone } from '@/components/ui/StatusChip';
import type { Channel, LogStatus, NotificationTemplate } from './api';

export const TAKA = '৳';

/** "1.2800" → "1.28", "1250.00" → "1,250". Formats the server's decimal string; never computes (rule 1). */
export function formatMoney(decimal: string): string {
    const negative = decimal.startsWith('-');
    const [whole = '', fraction = ''] = (negative ? decimal.slice(1) : decimal).split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const trimmed = fraction.replace(/0+$/, '');
    return `${negative ? '-' : ''}${grouped}${trimmed ? `.${trimmed.padEnd(2, '0')}` : ''}`;
}

/** ৳ set in the currency face (Hanken has no ৳ — 06-admin-feature-checklist §0). */
export function Taka({ amount, signClass = 't-admin-currency-num' }: { amount: string; signClass?: string }) {
    return (
        <>
            <span className={signClass}>{TAKA}</span>
            {formatMoney(amount)}
        </>
    );
}

export const channelIcon: Record<Channel, Icon> = { whatsapp: WhatsappLogoIcon, sms: ChatTextIcon, email: EnvelopeSimpleIcon };

export function channelLabel(channel: Channel): MessageKey {
    return `notif.channel.${channel}` as MessageKey;
}

export function eventLabel(key: string): MessageKey {
    return `notif.event.${key}` as MessageKey;
}

export function whenLabel(key: string): MessageKey {
    return `notif.when.${key}` as MessageKey;
}

const statusTone: Record<LogStatus, StatusTone> = {
    queued: 'info',
    sent: 'success',
    failed: 'warning',
    dead_lettered: 'danger',
    skipped: 'neutral',
};

/** StatusChip 185:79 for a message: label + icon shape, never colour alone. */
export function LogStatusChip({ status }: { status: LogStatus }) {
    const { t } = useI18n();
    return <StatusChip tone={statusTone[status]} label={t(`notif.status.${status}` as MessageKey)} />;
}

/** "2:31 pm" / "08 Nov 9:40 am" in Dhaka time, Western digits (rules 11, 36). */
export function dhakaWhen(iso: string, locale: string): { day: string; time: string } {
    const date = new Date(iso);
    const tag = locale === 'bn' ? 'bn-BD' : 'en-GB';
    return {
        day: new Intl.DateTimeFormat(tag, { timeZone: 'Asia/Dhaka', day: '2-digit', month: 'short', numberingSystem: 'latn' }).format(date),
        time: new Intl.DateTimeFormat(tag, { timeZone: 'Asia/Dhaka', hour: 'numeric', minute: '2-digit', hour12: true, numberingSystem: 'latn' }).format(date).toLowerCase(),
    };
}

/** Toggle (VariantMatrixEditor "On" column 201:1201 / Automations card 223:16055) — a real switch. */
export function Toggle({ checked, onChange, label, disabled }: { checked: boolean; onChange: (next: boolean) => void; label: string; disabled?: boolean }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            disabled={disabled}
            onClick={(event) => {
                event.stopPropagation();
                onChange(!checked);
            }}
            className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition-colors duration-150 ease-standard disabled:cursor-not-allowed disabled:opacity-60 ${
                checked ? 'bg-brand' : 'bg-line-strong'
            }`}
        >
            <span aria-hidden className={`size-4 rounded-full bg-surface shadow-e1 transition-transform duration-150 ease-standard ${checked ? 'translate-x-4' : 'translate-x-0'}`} />
        </button>
    );
}

/** A card panel from the Automations editor column (223:16116): radius/md, hairline, e1, 20 padding, 14 gap. */
export function Panel({ title, subtitle, children, label }: { title: string; subtitle?: string; children: ReactNode; label?: string }) {
    return (
        <section aria-label={label ?? title} className="flex w-full min-w-0 flex-col gap-3.5 rounded-md border border-line-default bg-surface p-5 shadow-e1">
            <header className="flex min-w-0 flex-col gap-0.5">
                <h2 className="t-admin-card-title text-fg-primary">{title}</h2>
                {subtitle && <p className="t-admin-caption text-fg-tertiary">{subtitle}</p>}
            </header>
            {children}
        </section>
    );
}

/** WHEN / IF / THEN / ELSE step (223:16121): tinted block, 18px glyph, overline + body. */
export function FlowStep({ icon: Glyph, label, tone, children }: { icon: Icon; label: string; tone: 'brand' | 'subtle' | 'success'; children: ReactNode }) {
    const fill = tone === 'brand' ? 'bg-brand-subtle' : tone === 'success' ? 'bg-success-subtle' : 'bg-subtle';
    return (
        <div className={`flex w-full items-center gap-3 rounded-sm px-3.5 py-3 ${fill}`}>
            <Glyph size={18} aria-hidden className="shrink-0 text-fg-primary" />
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <p className="t-admin-overline text-fg-secondary">{label}</p>
                <p className="t-admin-body text-fg-primary">{children}</p>
            </div>
        </div>
    );
}

/** The 2×16 connector between flow steps (223:16129). */
export function FlowLine() {
    return (
        <div aria-hidden className="flex ps-7">
            <span className="h-4 w-0.5 bg-line-strong" />
        </div>
    );
}

/** A catalogue key that may not exist: the template catalog is read dynamically (P7). */
export function maybeKey(key: string): MessageKey | null {
    return key in enMessages ? (key as MessageKey) : null;
}

/**
 * A template's display name: staff-made ones carry their own EN/BN name; system ones use the
 * admin catalogue's event label, and a key the catalogue does not know yet shows as the key.
 */
export function templateTitle(tpl: Pick<NotificationTemplate, 'key' | 'is_custom' | 'name_en' | 'name_bn'>, t: (key: MessageKey) => string, locale: string): string {
    if (tpl.is_custom) return (locale === 'bn' ? tpl.name_bn || tpl.name_en : tpl.name_en || tpl.name_bn) ?? tpl.key;
    const key = maybeKey(`notif.event.${tpl.key}`);
    return key ? t(key) : tpl.key;
}

/** What a variable stands for, when the catalogue describes it. */
export function variableHint(name: string, t: (key: MessageKey) => string): string | null {
    const key = maybeKey(`notif.var.${name}`);
    return key ? t(key) : null;
}
