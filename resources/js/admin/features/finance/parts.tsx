import { useEffect, useId, useRef, type ReactNode } from 'react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { TabLink, TabList } from '@/components/ui/Tabs';
import { Button } from '@/components/ui/Button';
import { TakaAmount } from '@/components/ui/TakaAmount';
import { TAKA, formatCount, formatMoney, takaText } from '@/lib/money';
import { CheckCircleIcon, DotsThreeIcon, InfoIcon, WarningCircleIcon } from '@phosphor-icons/react';
import type { ExpenseStatus, LedgerType, PaymentMethod } from './api';

/* Money and counts: the admin's one formatter (lib/money — lakh grouping, owner decision 2026-11-16). */
export { TAKA, formatCount, formatMoney };

/** A taka amount: the sign in the currency face, the figures in the surrounding style. */
export function Taka({ amount, signClass = 't-admin-currency-num' }: { amount: string; signClass?: string }) {
    return <TakaAmount amount={amount} signClass={signClass} />;
}

/** Today's Dhaka calendar date minus `daysBack`, as YYYY-MM-DD — the API buckets by Dhaka day (rule 11). */
export function dhakaDate(daysBack = 0): string {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    const day = new Date(`${parts}T00:00:00Z`);
    day.setUTCDate(day.getUTCDate() - daysBack);
    return day.toISOString().slice(0, 10);
}

/** "08 Nov" (a Dhaka date string, displayed as-is: no timezone shift). */
export function shortDate(ymd: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { timeZone: 'UTC', day: '2-digit', month: 'short', numberingSystem: 'latn' }).format(new Date(`${ymd}T00:00:00Z`));
}

/** "November" for the month of a Dhaka date. */
export function monthName(ymd: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { timeZone: 'UTC', month: 'long' }).format(new Date(`${ymd}T00:00:00Z`));
}

/** "02–08 Nov" for a range label. */
export function rangeLabel(from: string, to: string): string {
    const fmt = (iso: string, opts: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat('en-GB', { timeZone: 'UTC', numberingSystem: 'latn', ...opts }).format(new Date(`${iso}T00:00:00Z`));
    // "15 Oct–13 Nov" when the range crosses a month, "07–13 Nov" when it does not.
    const sameMonth = from.slice(0, 7) === to.slice(0, 7);
    return `${fmt(from, sameMonth ? { day: '2-digit' } : { day: '2-digit', month: 'short' })}–${fmt(to, { day: '2-digit', month: 'short' })}`;
}

export const statusLabel = (status: ExpenseStatus): MessageKey => `expenses.status.${status}` as MessageKey;
export const methodLabel = (method: PaymentMethod): MessageKey => `expenses.method.${method}` as MessageKey;
export const ledgerTypeLabel = (type: LedgerType): MessageKey => `finLedger.type.${type}` as MessageKey;

/**
 * The finance section's one tab strip (Figma B · 32 FinanceTabs 422:71915): P&L · Daily reports ·
 * Expenses · Expense categories · Ledger · COD settlements · Profitability · Capital — shared by
 * every finance screen and the reports screens. The money-book tabs (expenses, categories,
 * ledger, settlements, capital) show only with finance.view; the report tabs follow the
 * reports' own guard.
 */
export function FinanceTabs() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const finance = can(me, 'finance.view');
    return (
        <TabList label={t('finNav.tabs')}>
            <TabLink to="/finance/pnl" label={t('reports.tab.pnl')} />
            <TabLink to="/finance/reports" label={t('reports.tab.daily')} />
            {finance && <TabLink to="/finance/expenses" end label={t('finNav.expenses')} />}
            {finance && <TabLink to="/finance/expenses/categories" label={t('finNav.categories')} />}
            {finance && <TabLink to="/finance/ledger" label={t('finNav.ledger')} />}
            {finance && <TabLink to="/finance/settlements" label={t('settlements.tab')} />}
            <TabLink to="/finance/profitability" label={t('reports.tab.profitability')} />
            {finance && <TabLink to="/finance/capital" label={t('finUi.tab.capital')} />}
        </TabList>
    );
}

export type NoticeTone = 'info' | 'warning' | 'success' | 'danger';

const noticeTones: Record<NoticeTone, { fill: string; icon: string; Icon: typeof InfoIcon }> = {
    info: { fill: 'bg-info-subtle', icon: 'text-feedback-info', Icon: InfoIcon },
    warning: { fill: 'bg-warning-subtle', icon: 'text-feedback-warning', Icon: WarningCircleIcon },
    success: { fill: 'bg-success-subtle', icon: 'text-feedback-success', Icon: CheckCircleIcon },
    danger: { fill: 'bg-danger-subtle', icon: 'text-feedback-danger', Icon: WarningCircleIcon },
};

/**
 * Figma "Notice · info" (Finance 422:71411): radius/sm, a tinted fill and a tinted 18px leading
 * icon, body-strong title over a caption — no coloured edge (04-admin-spec rule 4a). The words
 * carry the meaning; the tint only supports them.
 */
export function Notice({ tone = 'info', title, children, role }: { tone?: NoticeTone; title: ReactNode; children?: ReactNode; role?: 'status' | 'alert' }) {
    const { fill, icon, Icon } = noticeTones[tone];
    return (
        <div role={role} className={`flex items-start gap-2.5 rounded-sm px-3.5 py-3 ${fill}`}>
            <Icon size={18} aria-hidden className={`mt-px shrink-0 ${icon}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <p className="t-admin-body-strong text-fg-primary">{title}</p>
                {children && <p className="t-admin-caption text-fg-secondary">{children}</p>}
            </div>
        </div>
    );
}

/** A KPI figure in Figma's Admin/num-lg (26px bold), the ৳ in its currency face. */
export function KpiMoney({ amount }: { amount: string }) {
    return <span className="t-admin-currency-num-lg">{takaText(amount, 'fixed')}</span>;
}

/**
 * Figma B · 32 sets every finance taka figure with two places and lakh grouping: "186400.00" →
 * "1,86,400.00", "-120.00" → "−120.00" — the admin's one formatter (lib/money `fixed`).
 */
export const formatFixed = (decimal: string): string => formatMoney(decimal, 'fixed');

/** A taka amount with two places ("৳12,500.00"); a negative carries a true minus before the sign. */
export function TakaFixed({ amount, signClass = 't-admin-currency-num' }: { amount: string; signClass?: string }) {
    return (
        <span className="tabular whitespace-nowrap">
            <TakaAmount amount={amount} places="fixed" signClass={signClass} />
        </span>
    );
}

/**
 * Figma StatCard 189:360 (Expenses 222:13676 stats): overline label, 26px value, caption.
 */
export function StatCard({ label, value, hint }: { label: string; value: ReactNode; hint: ReactNode }) {
    return (
        <div className="flex min-w-0 flex-col gap-1.5 overflow-hidden rounded-md border border-line-default bg-surface p-4 shadow-e1">
            <p className="t-admin-overline text-fg-secondary">{label}</p>
            <p className="tabular text-fg-primary">{value}</p>
            <p className="t-admin-caption text-fg-tertiary">{hint}</p>
        </div>
    );
}

/**
 * A small modal form (the AdjustmentDialog shell, 520 wide, radius/lg, e4) on <dialog>.showModal():
 * focus trap, Esc closes. `children` are the fields; submit runs `onSubmit`.
 */
export function FormDialog({
    open,
    title,
    body,
    submitLabel,
    danger,
    pending,
    onSubmit,
    onClose,
    children,
}: {
    open: boolean;
    title: string;
    body: string;
    submitLabel: string;
    danger?: boolean;
    pending?: boolean;
    onSubmit: () => void;
    onClose: () => void;
    children?: ReactNode;
}) {
    const { t } = useI18n();
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) {
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }
        if (!open && dialog.open) {
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        }
    }, [open]);

    return (
        <dialog ref={ref} aria-labelledby={titleId} onClose={onClose} onCancel={onClose} className="m-auto w-[min(100%-2rem,32.5rem)] rounded-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay">
            <form
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    onSubmit();
                }}
            >
                <div className="flex flex-col gap-2.5 px-6 pt-6 pb-4">
                    <h2 id={titleId} className="t-admin-section text-fg-primary">
                        {title}
                    </h2>
                    <p className="t-admin-body text-fg-secondary">{body}</p>
                </div>
                {children && (
                    <fieldset disabled={pending} className="flex min-w-0 flex-col gap-3.5 px-6 pb-5">
                        {children}
                    </fieldset>
                )}
                <div className="flex justify-end gap-3 border-t border-line-default px-6 py-4">
                    <Button type="button" variant="ghost" onClick={onClose} autoFocus>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" variant={danger ? 'danger' : 'primary'} disabled={pending}>
                        {submitLabel}
                    </Button>
                </div>
            </form>
        </dialog>
    );
}

/** Toggle (VariantMatrixEditor "On" 201:1201 look) — a real switch for assistive tech. */
export function Toggle({ checked, onChange, label, disabled }: { checked: boolean; onChange: (next: boolean) => void; label: string; disabled?: boolean }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition-colors duration-150 ease-standard disabled:cursor-not-allowed disabled:opacity-60 ${
                checked ? 'bg-brand' : 'bg-line-strong'
            }`}
        >
            <span aria-hidden className={`size-4 rounded-full bg-surface shadow-e1 transition-transform duration-150 ease-standard ${checked ? 'translate-x-4' : 'translate-x-0'}`} />
        </button>
    );
}

/**
 * A row's secondary actions behind one "More actions" button (Figma's expense rows carry no
 * action column, so the row keeps its single height). Native <details>: keyboard and screen
 * reader support come with it; it closes when focus or a click leaves it, or on Escape.
 */
export function RowMenu({ label, children }: { label: string; children: ReactNode }) {
    const ref = useRef<HTMLDetailsElement>(null);
    useEffect(() => {
        const close = (event: Event) => {
            const el = ref.current;
            if (!el?.open) return;
            if (event instanceof KeyboardEvent ? event.key === 'Escape' : !el.contains(event.target as Node)) el.open = false;
        };
        document.addEventListener('pointerdown', close);
        document.addEventListener('keydown', close);
        return () => {
            document.removeEventListener('pointerdown', close);
            document.removeEventListener('keydown', close);
        };
    }, []);
    return (
        <details ref={ref} className="relative">
            <summary aria-label={label} title={label} className="inline-grid size-8 cursor-pointer list-none place-items-center rounded-sm border border-line-default bg-surface text-fg-primary hover:bg-subtle [&::-webkit-details-marker]:hidden">
                <DotsThreeIcon size={16} aria-hidden />
            </summary>
            <div className="absolute end-0 top-full z-20 mt-1 flex min-w-40 flex-col rounded-md border border-line-default bg-surface p-1 shadow-e2" onClick={() => ref.current?.removeAttribute('open')}>
                {children}
            </div>
        </details>
    );
}

/** One item in a RowMenu. */
export function RowMenuItem({ onClick, children, disabled }: { onClick: () => void; children: ReactNode; disabled?: boolean }) {
    return (
        <button type="button" disabled={disabled} onClick={onClick} className="flex h-8 items-center rounded-xs px-3 text-start t-admin-body text-fg-primary hover:bg-subtle disabled:text-fg-disabled">
            {children}
        </button>
    );
}
