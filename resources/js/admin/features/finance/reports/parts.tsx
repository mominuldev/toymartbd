import { useId, type ReactNode } from 'react';
import { useSearchParams } from 'react-router';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { FinanceTabs } from '../parts';
import type { Range } from './api';

/** The taka sign — a currency symbol, not translatable copy. */
export const TAKA = '৳';
/** A true minus (U+2212), as the admin spec sets money (04-admin-spec Part 0). */
export const MINUS = '−';

/** "13910.00" → "13,910", "-602.50" → "-602.5": groups the server's decimal string; never parses it into a float (rule 1). */
export function formatAmount(decimal: string): string {
    const negative = decimal.startsWith('-');
    const [whole = '', fraction = ''] = (negative ? decimal.slice(1) : decimal).split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const trimmed = fraction.replace(/0+$/, '');
    return `${negative ? '-' : ''}${grouped}${trimmed ? `.${trimmed}` : ''}`;
}

/** Western digits with grouping in both locales (rule 36). */
export function formatCount(value: number): string {
    return new Intl.NumberFormat('en-US').format(value);
}

export function isNegative(decimal: string): boolean {
    return decimal.startsWith('-') && !/^-0(\.0+)?$/.test(decimal);
}

/**
 * A taka amount as Figma sets it: a true minus before the sign, the ৳ in the currency
 * face, the figures in the surrounding style. `plus` prefixes "+" on a positive amount.
 */
export function Money({ amount, signClass = 't-admin-currency-num', plus = false }: { amount: string; signClass?: string; plus?: boolean }) {
    const negative = isNegative(amount);
    const digits = formatAmount(negative ? amount.slice(1) : amount.replace(/^-/, ''));
    return (
        <span className="tabular whitespace-nowrap">
            {negative ? MINUS : plus && digits !== '0' ? '+' : ''}
            <span className={signClass}>{TAKA}</span>
            {digits}
        </span>
    );
}

/** A server percentage string ("41.01") as "41.01%", or a dash. */
export function Percent({ value }: { value: string | null | undefined }) {
    if (value === null || value === undefined) return <span className="text-fg-tertiary">—</span>;
    return <span className="tabular">{`${value.startsWith('-') ? MINUS + value.slice(1) : value}%`}</span>;
}

/** Today's Dhaka calendar date (rule 11), Y-m-d — for date-input bounds and preset labels only, never for money. */
export function dhakaToday(now: Date = new Date()): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
}

function shift(date: string, days: number): string {
    const d = new Date(`${date}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
}

export const presets = ['last7', 'last30', 'thisMonth', 'lastMonth', 'thisYear', 'custom'] as const;
export type Preset = (typeof presets)[number];

/** The Dhaka-date range a preset names. Labels for the picker; the server re-validates every date. */
export function presetRange(preset: Exclude<Preset, 'custom'>, today: string = dhakaToday()): Range {
    const [y, m] = today.split('-');
    switch (preset) {
        case 'last7':
            return { from: shift(today, -6), to: today };
        case 'last30':
            return { from: shift(today, -29), to: today };
        case 'thisMonth':
            return { from: `${y}-${m}-01`, to: today };
        case 'lastMonth': {
            const end = shift(`${y}-${m}-01`, -1);
            return { from: `${end.slice(0, 8)}01`, to: end };
        }
        case 'thisYear':
            return { from: `${y}-01-01`, to: today };
    }
}

/** "02 Nov 2026" in the UI language, Western digits (rule 36). */
export function formatDay(date: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC', numberingSystem: 'latn' }).format(new Date(`${date}T00:00:00Z`));
}

/**
 * The period lives in the URL (`?from=…&to=…&preset=…`), so a report is a shareable link.
 * No range → the server's default (last seven Dhaka days).
 */
export function useRangeParams() {
    const [params, setParams] = useSearchParams();
    const date = (key: string) => (/^\d{4}-\d{2}-\d{2}$/.test(params.get(key) ?? '') ? params.get(key)! : '');
    const range: Range = { from: date('from'), to: date('to') };
    const presetParam = params.get('preset') as Preset | null;
    const preset: Preset = presetParam && presets.includes(presetParam) ? presetParam : range.from ? 'custom' : 'last7';

    const set = (entries: Record<string, string>) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                for (const [key, value] of Object.entries(entries)) {
                    if (value) next.set(key, value);
                    else next.delete(key);
                }
                return next;
            },
            { replace: true },
        );

    const choosePreset = (next: Preset) => {
        if (next === 'custom') set({ preset: 'custom', from: range.from || presetRange('last7').from, to: range.to || dhakaToday() });
        else if (next === 'last7') set({ preset: '', from: '', to: '' });
        else set({ preset: next, ...presetRange(next) });
    };

    return { range, preset, params, set, choosePreset };
}

/** DateRangePicker (Figma 193:741) as native controls: a preset select, and from/to dates for a custom range. */
export function PeriodPicker({ range, preset, onPreset, onRange, today = dhakaToday() }: { range: Range; preset: Preset; onPreset: (p: Preset) => void; onRange: (r: Range) => void; today?: string }) {
    const { t } = useI18n();
    const id = useId();
    return (
        <div className="flex flex-wrap items-center gap-2">
            <label htmlFor={`${id}-preset`} className="sr-only">
                {t('reports.period.label')}
            </label>
            <Select id={`${id}-preset`} value={preset} onChange={(e) => onPreset(e.target.value as Preset)} className="w-48">
                {presets.map((p) => (
                    <option key={p} value={p}>
                        {t(`reports.period.${p}` as MessageKey)}
                    </option>
                ))}
            </Select>
            {preset === 'custom' && (
                <>
                    <label htmlFor={`${id}-from`} className="sr-only">
                        {t('reports.period.from')}
                    </label>
                    <div className="w-40 shrink-0"><Input id={`${id}-from`} type="date" max={range.to || today} value={range.from} onChange={(e) => onRange({ ...range, from: e.target.value })} className="tabular" /></div>
                    <label htmlFor={`${id}-to`} className="sr-only">
                        {t('reports.period.to')}
                    </label>
                    <div className="w-40 shrink-0"><Input id={`${id}-to`} type="date" min={range.from} max={today} value={range.to} onChange={(e) => onRange({ ...range, to: e.target.value })} className="tabular" /></div>
                </>
            )}
        </div>
    );
}

/** The finance section's single tab strip (reports + expenses + ledger), defined once in ../parts. */
export function ReportTabs() {
    return <FinanceTabs />;
}

/** A labelled card (radius/lg, border/default, e1) for the P&L rail and report panels. */
export function Card({ children, className = '' }: { children: ReactNode; className?: string }) {
    return <section className={`rounded-lg border border-line-default bg-surface shadow-e1 ${className}`}>{children}</section>;
}
