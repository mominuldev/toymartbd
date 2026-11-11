import { StatusChip, type StatusTone } from '@/components/ui/StatusChip';
import { useI18n } from '@/i18n/I18nProvider';
import type { ProductStatus } from './api';

/**
 * The two pieces the shared components/ui set does not have: the product-status chip
 * (StatusChip with this screen's three states) and the matrix "On" toggle.
 */

/** The taka sign — a currency symbol, not translatable copy. */
export const TAKA = '৳';

/**
 * A taka amount as Figma sets it (DataTable price cells, B · 11 Products 241:37251): the sign in
 * Admin/currency/num (Fredoka), the figures in the surrounding text style. `amount` is already
 * formatted for display — this never computes.
 */
export function Taka({ amount }: { amount: string }) {
    return (
        <>
            <span className="t-admin-currency-num">{TAKA}</span>
            {amount}
        </>
    );
}

const statusChip: Record<ProductStatus, { tone: StatusTone; label: 'products.status.active' | 'products.status.draft' | 'products.status.archived' }> = {
    active: { tone: 'success', label: 'products.status.active' },
    draft: { tone: 'neutral', label: 'products.status.draft' },
    archived: { tone: 'neutral', label: 'products.status.archived' },
};

/** StatusChip 185:79 for a product: Published (success), Draft and Archived (neutral). */
export function ProductStatusChip({ status }: { status: ProductStatus }) {
    const { t } = useI18n();
    return <StatusChip tone={statusChip[status].tone} label={t(statusChip[status].label)} />;
}

/** Toggle from the VariantMatrixEditor "On" column (201:1201) — a real switch for assistive tech. */
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
