import type { ComponentType, ReactNode } from 'react';
import { ChatCircleIcon, ChatsCircleIcon, CheckCircleIcon, EnvelopeSimpleIcon, HeadsetIcon, MessengerLogoIcon, WhatsappLogoIcon, type IconProps } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import type { Channel, Conversation } from './api';

/** Two initials from a name; a single word gives one. Works for Latin and Bangla names. */
export function initialsOf(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    const letters = parts.length > 1 ? [parts[0], parts[parts.length - 1]] : parts;
    return letters.map((part) => Array.from(part ?? '')[0] ?? '').join('').toUpperCase();
}

/** Figma Avatar 52:483 Medium · Initials — decorative; the name is always next to it. */
export function ContactAvatar({ name }: { name: string }) {
    return (
        <span aria-hidden className="grid size-9 shrink-0 place-items-center rounded-full border border-line-default bg-surface font-display-latin text-xs/none font-bold text-fg-primary">
            {initialsOf(name) || '?'}
        </span>
    );
}

/** Bangla script gets `lang="bn"` and the taller `.lang-bn` line-height (rule 38). */
export const BANGLA = /[ঀ-৿]/;
export function langProps(text: string | null | undefined): { lang?: string; className: string } {
    return text && BANGLA.test(text) ? { lang: 'bn', className: 'lang-bn' } : { className: '' };
}

const channelLook: Record<Channel, { icon: ComponentType<IconProps>; tile: string; tint: string }> = {
    whatsapp: { icon: WhatsappLogoIcon, tile: 'bg-tile-mint', tint: 'text-feedback-success' },
    messenger: { icon: MessengerLogoIcon, tile: 'bg-tile-sky', tint: 'text-feedback-info' },
    live_chat: { icon: ChatCircleIcon, tile: 'bg-tile-lavender', tint: 'text-fg-brand' },
    web_form: { icon: EnvelopeSimpleIcon, tile: 'bg-tile-butter', tint: 'text-feedback-warning' },
};

export const channelLabel = (channel: Channel): MessageKey => `inbox.channel.${channel}` as MessageKey;

/** A small pastel chip: icon + word, never colour alone. */
export function Chip({ icon: Icon, tile, tint, children }: { icon: ComponentType<IconProps>; tile: string; tint: string; children: ReactNode }) {
    return (
        <span className={`inline-flex h-5.5 items-center gap-1 rounded-xs px-1.5 t-admin-caption-strong whitespace-nowrap text-fg-primary ${tile}`}>
            <Icon size={13} weight="fill" aria-hidden className={`shrink-0 ${tint}`} />
            {children}
        </span>
    );
}

export function ChannelChip({ channel }: { channel: Channel }) {
    const { t } = useI18n();
    const look = channelLook[channel];
    return (
        <Chip icon={look.icon} tile={look.tile} tint={look.tint}>
            {t(channelLabel(channel))}
        </Chip>
    );
}

export function NeedsHumanChip() {
    const { t } = useI18n();
    return (
        <Chip icon={HeadsetIcon} tile="bg-tile-peach" tint="text-feedback-warning">
            {t('inbox.needsHuman')}
        </Chip>
    );
}

export function ResolvedChip() {
    const { t } = useI18n();
    return (
        <Chip icon={CheckCircleIcon} tile="bg-tile-mint" tint="text-feedback-success">
            {t('inbox.status.resolved')}
        </Chip>
    );
}

export const ChannelIcon = ({ channel, size = 14 }: { channel: Channel; size?: number }) => {
    const { icon: Icon, tint } = channelLook[channel];
    return <Icon size={size} weight="fill" aria-hidden className={`shrink-0 ${tint}`} />;
};

/** The contact's display name: theirs, the linked customer's, or "WhatsApp contact". */
export function useContactName(): (c: Pick<Conversation, 'contact' | 'channel'>) => string {
    const { t } = useI18n();
    return (c) => c.contact.name ?? t('inbox.unknownContact', { channel: t(channelLabel(c.channel)) });
}

/** "+8801841523907" → "01841 523 907" (Figma). A masked number is shown as the server sent it. */
export function displayPhone(contact: Conversation['contact']): string | null {
    if (contact.phone) {
        const national = contact.phone.replace(/^\+88/, '');
        return /^\d{11}$/.test(national) ? `${national.slice(0, 5)} ${national.slice(5, 8)} ${national.slice(8)}` : national;
    }
    return contact.phone_masked;
}

/** "now", "2m", "1h", "3d" — the list's compact age, Western digits (rule 36). */
export function useAge(): (iso: string | null, now?: number) => string {
    const { t } = useI18n();
    return (iso, now = Date.now()) => {
        if (!iso) return '';
        const minutes = Math.max(0, Math.floor((now - new Date(iso).getTime()) / 60_000));
        if (minutes < 1) return t('inbox.age.now');
        if (minutes < 60) return t('inbox.age.minutes', { n: minutes });
        if (minutes < 1440) return t('inbox.age.hours', { n: Math.floor(minutes / 60) });
        return t('inbox.age.days', { n: Math.floor(minutes / 1440) });
    };
}

const tag = (locale: string) => (locale === 'bn' ? 'bn-BD' : 'en-GB');

/** "2:28 pm" in Dhaka time, Western digits (rules 11, 36). */
export const dhakaTime = (iso: string, locale: string): string =>
    new Intl.DateTimeFormat(tag(locale), { timeZone: 'Asia/Dhaka', numberingSystem: 'latn', hour: 'numeric', minute: '2-digit', hour12: true }).format(new Date(iso));

/** "15 Nov, 2:28 pm" in Dhaka time. */
export const dhakaDayTime = (iso: string, locale: string): string =>
    new Intl.DateTimeFormat(tag(locale), { timeZone: 'Asia/Dhaka', numberingSystem: 'latn', day: '2-digit', month: 'short', hour: 'numeric', minute: '2-digit', hour12: true }).format(new Date(iso));

export const ChatsIcon = ChatsCircleIcon;
