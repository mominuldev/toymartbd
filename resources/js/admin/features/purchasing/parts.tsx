import { useEffect, useRef, type ReactNode } from 'react';
import { ImageIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { StatusChip, type StatusTone } from '@/components/ui/StatusChip';
import { dataTable } from '@/components/ui/DataTable';
import { TakaAmount } from '@/components/ui/TakaAmount';
import { TAKA, formatCount, formatMoney, isZeroAmount, takaText as takaTextOf } from '@/lib/money';
import type { PurchaseLine, PurchaseStatus } from './api';

/* Money and counts: the admin's one formatter (lib/money — lakh grouping, owner decision 2026-11-16). */
export { TAKA, formatMoney, isZeroAmount };

/**
 * A taka amount at exactly 2 decimals ("23400" → "23,400.00", "832.1429" → "832.14") — the
 * premium screens show every taka figure to the poisha, never 4 dp (lib/money `round`).
 */
export const formatTaka = (decimal: string): string => formatMoney(decimal, 'round');

/** "৳23,400.00" as plain text, for sentences and aria labels. */
export const takaText = (decimal: string): string => takaTextOf(decimal, 'round');

/** A taka amount at 2 decimals, the sign in the currency face (Figma's premium tables and rails). */
export function Money({ amount, signClass = 't-admin-currency-num' }: { amount: string; signClass?: string }) {
    return <TakaAmount amount={amount} places="round" signClass={signClass} />;
}

/**
 * Received progress (Figma "18 of 40 units" + a 4px bar): units, never money. Brand while
 * stock is still to come, success once everything is in. The bar is drawing only — the
 * caption carries the figures, and role=progressbar gives them to a screen reader.
 */
export function ReceivedBar({ received, ordered, label }: { received: number; ordered: number; label: string }) {
    const done = ordered > 0 && received >= ordered;
    return (
        <div
            role="progressbar"
            aria-label={label}
            aria-valuemin={0}
            aria-valuemax={ordered}
            aria-valuenow={received}
            className="h-1 w-full max-w-33 overflow-hidden rounded-full bg-line-default"
        >
            <div className={`h-full rounded-full ${done ? 'bg-feedback-success' : 'bg-brand'}`} style={{ width: ordered > 0 ? `${Math.min(received, ordered) * (100 / ordered)}%` : '0%' }} />
        </div>
    );
}

/** Units, lakh-grouped with Western digits in both locales (lib/money, rule 36). */
export const formatUnits = formatCount;

/** A Y-m-d calendar date as "12 Nov" (Dhaka dates are already calendar dates; no zone shift). */
export function formatDay(ymd: string, locale: string, withYear = false, withWeekday = false): string {
    const [y, m, d] = ymd.split('-').map(Number);
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', {
        timeZone: 'UTC',
        day: 'numeric',
        month: 'short',
        ...(withYear ? { year: 'numeric' } : {}),
        ...(withWeekday ? { weekday: 'short' } : {}),
        numberingSystem: 'latn',
    }).format(new Date(Date.UTC(y ?? 1970, (m ?? 1) - 1, d ?? 1)));
}

/** A taka amount as Figma sets it: the sign in the currency face, the figures in the surrounding style. */
export function Taka({ amount, signClass = 't-admin-currency-num' }: { amount: string; signClass?: string }) {
    return <TakaAmount amount={amount} signClass={signClass} />;
}

/**
 * Figma "Purchasing · Premium" 414:70079: Draft plain, Waiting for stock ⓘ info, Partly
 * received ⚠ warning, Received ✓ success, Closed short plain, Cancelled ✕ danger.
 */
const statusTone: Record<PurchaseStatus, StatusTone> = {
    draft: 'neutral',
    ordered: 'info',
    partially_received: 'warning',
    received: 'success',
    cancelled: 'danger',
    closed_short: 'neutral',
};

const plain: PurchaseStatus[] = ['draft', 'closed_short'];

/** PO status: text + icon shape + tint (Figma StatusChip 185:79), never colour alone. */
export function PurchaseStatusChip({ status }: { status: PurchaseStatus }) {
    const { t } = useI18n();
    return <StatusChip label={t(`purchasing.status.${status}` as MessageKey)} tone={statusTone[status]} showIcon={!plain.includes(status)} />;
}

export function supplierTerms(days: number, t: (key: MessageKey, values?: Record<string, string | number>) => string): string {
    return days > 0 ? t('purchasing.terms', { days }) : t('purchasing.termsCash');
}

/** 36 px thumbnail + product / variant name + SKU (the receive rows and PO lines in Figma 213:8325). */
export function LineProduct({ line, mono = true }: { line: Pick<PurchaseLine, 'image_url' | 'product_name' | 'product_name_bn' | 'variant_name' | 'variant_name_bn' | 'sku'>; mono?: boolean }) {
    const { locale } = useI18n();
    const bn = locale === 'bn';
    const product = bn ? (line.product_name_bn ?? line.product_name) : line.product_name;
    const variant = bn ? (line.variant_name_bn ?? line.variant_name) : line.variant_name;
    return (
        <div className="flex min-w-0 items-center gap-2.5">
            {line.image_url ? (
                <img src={line.image_url} alt="" className="size-9 shrink-0 rounded-xs-plus object-cover" />
            ) : (
                <span aria-hidden className="grid size-9 shrink-0 place-items-center rounded-xs-plus bg-subtle text-fg-tertiary">
                    <ImageIcon size={16} />
                </span>
            )}
            <div className="flex min-w-0 flex-col gap-0.5">
                <p className="truncate t-admin-body-strong text-fg-primary">{variant ? `${product} / ${variant}` : product}</p>
                {mono && <p className="truncate t-admin-mono-sm text-fg-tertiary">{line.sku}</p>}
            </div>
        </div>
    );
}

export function lineName(line: Pick<PurchaseLine, 'product_name' | 'product_name_bn' | 'variant_name' | 'variant_name_bn'>, locale: string): string {
    const bn = locale === 'bn';
    const product = bn ? (line.product_name_bn ?? line.product_name) : line.product_name;
    const variant = bn ? (line.variant_name_bn ?? line.variant_name) : line.variant_name;
    return variant ? `${product} / ${variant}` : product;
}

/** A label/amount row of a totals panel (Figma "Goods" / "Inbound freight" / "Total payable"). */
export function TotalRow({ label, amount, strong }: { label: string; amount: string; strong?: boolean }) {
    return (
        <div className={`flex items-center gap-2 py-1.5 ${strong ? 'border-t border-line-strong t-admin-body-strong' : ''}`}>
            <span className={`flex-1 ${strong ? '' : 't-admin-body'} text-fg-secondary`}>{label}</span>
            <span className="tabular whitespace-nowrap text-fg-primary">
                <Taka amount={amount} signClass="t-admin-currency-num" />
            </span>
        </div>
    );
}

export function Th({ children, end, className = '' }: { children: ReactNode; end?: boolean; className?: string }) {
    return (
        <th scope="col" className={`${end ? dataTable.th.replace('text-start', 'text-end') : dataTable.th} ${className}`}>
            {children}
        </th>
    );
}

/** Column name in front of a value on the stacked (below lg) row; hidden where the header shows. */
export function CellLabel({ text }: { text: string }) {
    return <span className="me-1.5 t-admin-caption text-fg-tertiary lg:hidden">{text}</span>;
}

/** Opens and closes a native <dialog> with `open`, falling back to the attribute where showModal is missing (jsdom). */
export function useDialog(open: boolean, onOpen: () => void) {
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

/** Today's Dhaka calendar date, Y-m-d (rule 11) — the default for a payment or an order date. */
export function dhakaToday(): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}
