import { useEffect, useRef, type ReactNode } from 'react';
import { CircleDashedIcon, ImageIcon, TruckIcon, type Icon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { StatusChip, type StatusTone } from '@/components/ui/StatusChip';
import { dataTable } from '@/components/ui/DataTable';
import type { PurchaseLine, PurchaseStatus } from './api';

/** The taka sign — a currency symbol, not translatable copy. */
export const TAKA = '৳';

/**
 * "48600.00" → "48,600", "312.5000" → "312.5". Groups and trims the server's decimal string;
 * never parses it into a float and never computes (rule 1).
 */
export function formatMoney(decimal: string): string {
    const negative = decimal.startsWith('-');
    const [whole = '', fraction = ''] = (negative ? decimal.slice(1) : decimal).split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const trimmed = fraction.replace(/0+$/, '');
    // A true minus sign (U+2212) for money, per the admin number rules.
    return `${negative ? '−' : ''}${grouped}${trimmed ? `.${trimmed}` : ''}`;
}

/** Western digits with grouping in both locales (rule 36). */
export function formatUnits(value: number): string {
    return new Intl.NumberFormat('en-US').format(value);
}

/** A Y-m-d calendar date as "12 Nov" (Dhaka dates are already calendar dates; no zone shift). */
export function formatDay(ymd: string, locale: string, withYear = false): string {
    const [y, m, d] = ymd.split('-').map(Number);
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', {
        timeZone: 'UTC',
        day: 'numeric',
        month: 'short',
        ...(withYear ? { year: 'numeric' } : {}),
        numberingSystem: 'latn',
    }).format(new Date(Date.UTC(y ?? 1970, (m ?? 1) - 1, d ?? 1)));
}

/** A taka amount as Figma sets it: the sign in the currency face, the figures in the surrounding style. */
export function Taka({ amount, signClass = 't-admin-currency-num' }: { amount: string; signClass?: string }) {
    return (
        <>
            <span className={signClass}>{TAKA}</span>
            {formatMoney(amount)}
        </>
    );
}

const statusTone: Record<PurchaseStatus, StatusTone> = {
    draft: 'neutral',
    ordered: 'info',
    partially_received: 'warning',
    received: 'success',
    cancelled: 'danger',
    closed_short: 'anomaly',
};

/** Figma B · 16 draws "Ordered" with a truck and "Partly received" with a dashed circle. */
const statusIcon: Partial<Record<PurchaseStatus, Icon>> = { ordered: TruckIcon, partially_received: CircleDashedIcon };

/** PO status: text + icon shape + tint (Figma StatusChip 185:79), never colour alone. */
export function PurchaseStatusChip({ status }: { status: PurchaseStatus }) {
    const { t } = useI18n();
    return <StatusChip label={t(`purchasing.status.${status}` as MessageKey)} tone={statusTone[status]} icon={statusIcon[status]} />;
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
