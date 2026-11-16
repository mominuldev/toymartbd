import { useEffect, useId, useRef, type ComponentType, type ReactNode } from 'react';
import { InfoIcon, XIcon, type IconProps } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { StatusChip } from '@/components/ui/StatusChip';
import { TAKA, takaText } from '@/lib/money';
import type { CapitalEntry, CapitalMethod, HolderRole } from './api';

/* The taka sign and money: the admin's one formatter (lib/money — lakh grouping, owner decision 2026-11-16). */
export { TAKA };

/** "৳12,50,000.00" — lakh grouping, two places (lib/money `fixed`); with `signed`, a "+" for money in and "−" for money out. */
export const taka = (decimal: string, signed = false): string => takaText(decimal, 'fixed', { plus: signed });

/** An amount the user typed ("2,00,000" or "200000.5") as the server will read it, or null. */
export function typedAmount(text: string): string | null {
    const plain = text.trim().replace(/,/g, '');
    return /^\d{1,12}(\.\d{1,2})?$/.test(plain) && !/^0+(\.0+)?$/.test(plain) ? plain : null;
}

/** "15 Nov 2026" (or "15 Nov" without the year) for a Dhaka date string, shown as-is. */
export function capitalDate(ymd: string, locale: string, withYear = true): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', {
        timeZone: 'UTC',
        day: 'numeric',
        month: 'short',
        ...(withYear ? { year: 'numeric' } : {}),
        numberingSystem: 'latn',
    }).format(new Date(`${ymd}T00:00:00Z`));
}

/** "Mominul Islam" → "MI". */
export function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : '')).toUpperCase();
}

export const methodKey = (method: CapitalMethod): MessageKey => `capital.method.${method}` as MessageKey;
export const roleKey = (role: HolderRole): MessageKey => `capital.role.${role}` as MessageKey;

/** Money in = the entry raised the holder's capital (server `net_effect`, never re-derived). */
export const isMoneyIn = (netEffect: string): boolean => !netEffect.startsWith('-');

/**
 * The KIND cell's chips: Money in (success) / Money out (warning); a reversal is a neutral
 * "Reversal"; an entry already reversed adds a neutral "Reversed" chip. Shape + tint + label.
 */
export function KindChips({ entry, short = false }: { entry: CapitalEntry; short?: boolean }) {
    const { t } = useI18n();
    if (entry.is_reversal) return <StatusChip tone="neutral" showIcon={!short} label={t('capital.kind.reversal')} />;
    const label = entry.kind === 'contribution' ? t(short ? 'capital.kind.inShort' : 'capital.kind.in') : t(short ? 'capital.kind.outShort' : 'capital.kind.out');
    if (short) return entry.reversed_by_id === null ? <StatusChip tone="neutral" showIcon={false} label={label} /> : <StatusChip tone="neutral" showIcon={false} label={t('capital.kind.reversed')} />;
    return (
        <span className="flex items-center gap-1.5 whitespace-nowrap">
            <StatusChip tone={entry.kind === 'contribution' ? 'success' : 'warning'} label={label} />
            {entry.reversed_by_id !== null && <StatusChip tone="neutral" showIcon={false} label={t('capital.kind.reversed')} />}
        </span>
    );
}

const noticeTone = {
    info: { fill: 'bg-info-subtle', tint: 'text-feedback-info' },
    success: { fill: 'bg-success-subtle', tint: 'text-feedback-success' },
    warning: { fill: 'bg-warning-subtle', tint: 'text-feedback-warning' },
} as const;

/** Figma "Notice · info/success/warning": tinted fill, 18px icon, strong title, caption body. */
export function Notice({ tone = 'info', icon: Icon = InfoIcon, title, children }: { tone?: keyof typeof noticeTone; icon?: ComponentType<IconProps>; title?: string; children: ReactNode }) {
    return (
        <div className={`flex items-start gap-2.5 rounded-sm px-3.5 py-3 ${noticeTone[tone].fill}`}>
            <Icon size={18} aria-hidden className={`mt-px shrink-0 ${noticeTone[tone].tint}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                {title && <p className="t-admin-body-strong text-fg-primary">{title}</p>}
                <p className="t-admin-caption text-fg-secondary">{children}</p>
            </div>
        </div>
    );
}

/** Opens and closes a native <dialog> with `open` (attribute fallback where showModal is missing, jsdom). */
export function useModal(open: boolean, onOpen: () => void) {
    const ref = useRef<HTMLDialogElement>(null);
    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) {
            onOpen();
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }
        if (!open && dialog.open) {
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);
    return ref;
}

type CapitalDialogProps = {
    open: boolean;
    onOpen: () => void;
    onClose: () => void;
    title: string;
    subtitle: string;
    /** 580 for the entry dialogs, 520 for the confirm (Figma 429:74946 / 429:75756). */
    width: 'wide' | 'narrow';
    pending: boolean;
    submitLabel: string;
    submitIcon?: ReactNode;
    danger?: boolean;
    footnote?: string;
    onSubmit: () => void;
    children: ReactNode;
};

/**
 * The capital dialogs' shell (Figma 429:74946): header with title, caption and a ghost close
 * button over a hairline; a 24×20 body with 18 between blocks; a footer with an optional note,
 * Cancel (ghost) and one primary — or danger, for the reverse confirm. Native <dialog>: focus
 * trap and Esc come with it.
 */
export function CapitalDialog({ open, onOpen, onClose, title, subtitle, width, pending, submitLabel, submitIcon, danger, footnote, onSubmit, children }: CapitalDialogProps) {
    const { t } = useI18n();
    const ref = useModal(open, onOpen);
    const titleId = useId();
    const subtitleId = useId();
    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            aria-describedby={subtitleId}
            onClose={onClose}
            onCancel={onClose}
            className={`m-auto max-h-[calc(100dvh-2rem)] rounded-md bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay ${width === 'wide' ? 'w-[min(100%-2rem,36.25rem)]' : 'w-[min(100%-2rem,32.5rem)]'}`}
        >
            <form
                noValidate
                className="flex max-h-[calc(100dvh-2rem)] flex-col"
                onSubmit={(event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    onSubmit();
                }}
            >
                <div className="flex items-start gap-3 border-b border-line-default ps-6 pe-5 pt-4.5 pb-4">
                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                        <h2 id={titleId} className="t-admin-card-title text-fg-primary">
                            {title}
                        </h2>
                        <p id={subtitleId} className="t-admin-caption text-fg-secondary">
                            {subtitle}
                        </p>
                    </div>
                    <IconButton aria-label={t('capital.dialog.close')} onClick={onClose}>
                        <XIcon size={18} aria-hidden />
                    </IconButton>
                </div>
                <fieldset disabled={pending} className="flex min-w-0 flex-col gap-4.5 overflow-y-auto px-6 py-5">
                    {children}
                </fieldset>
                <div className="flex flex-wrap items-center gap-3 border-t border-line-default px-6 py-3.5">
                    <p className="min-w-40 flex-1 t-admin-caption text-fg-tertiary">{footnote}</p>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" variant={danger ? 'danger' : 'primary'} disabled={pending}>
                        {submitIcon}
                        {submitLabel}
                    </Button>
                </div>
            </form>
        </dialog>
    );
}
