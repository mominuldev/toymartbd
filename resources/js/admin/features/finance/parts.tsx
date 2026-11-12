import { useEffect, useId, useRef, type ReactNode } from 'react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { TabLink, TabList } from '@/components/ui/Tabs';
import { Button } from '@/components/ui/Button';
import type { ExpenseStatus, LedgerType, PaymentMethod } from './api';

/** The taka sign — a currency symbol, not translatable copy. */
export const TAKA = '৳';

/**
 * "25000.00" → "25,000", "1050.50" → "1,050.5". Groups and trims the server's decimal string;
 * never parses it into a float and never computes (rule 1).
 */
export function formatMoney(decimal: string): string {
    const negative = decimal.startsWith('-');
    const [whole = '', fraction = ''] = (negative ? decimal.slice(1) : decimal).split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const trimmed = fraction.replace(/0+$/, '');
    return `${negative ? '−' : ''}${grouped}${trimmed ? `.${trimmed}` : ''}`;
}

/** A taka amount: the sign in the currency face, the figures in the surrounding style. */
export function Taka({ amount, signClass = 't-admin-currency-num' }: { amount: string; signClass?: string }) {
    return (
        <>
            <span className={signClass}>{TAKA}</span>
            {formatMoney(amount)}
        </>
    );
}

/** Western digits with grouping in both locales (rule 36). */
export function formatCount(value: number): string {
    return new Intl.NumberFormat('en-US').format(value);
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
    return `${fmt(from, { day: '2-digit' })}–${fmt(to, { day: '2-digit', month: 'short' })}`;
}

export const statusLabel = (status: ExpenseStatus): MessageKey => `expenses.status.${status}` as MessageKey;
export const methodLabel = (method: PaymentMethod): MessageKey => `expenses.method.${method}` as MessageKey;
export const ledgerTypeLabel = (type: LedgerType): MessageKey => `finLedger.type.${type}` as MessageKey;

/**
 * The finance section's one tab strip (IA §1: P&L · Daily reports · Expenses · Ledger & COD
 * receivables · Profitability), shared by these screens and the reports screens. Expense and
 * ledger tabs show only with finance.view; the report tabs follow the reports' own guard.
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
            <TabLink to="/finance/profitability" label={t('reports.tab.profitability')} />
        </TabList>
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
