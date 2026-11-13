import { useState, type ComponentType, type ReactNode } from 'react';
import { CheckIcon, XIcon, type IconProps } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { TabLink, TabList } from '@/components/ui/Tabs';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { StatusChip, type StatusTone } from '@/components/ui/StatusChip';
import { dataTable } from '@/components/ui/DataTable';
import { scopeTypes, usePick, type Coupon, type PickItem, type PickKind, type PromotionStatus, type ScopeType, type UsageRow, type UsageSummary } from './api';

type T = (key: MessageKey, vars?: Record<string, string | number>) => string;

export const TAKA = '৳';

/** Card surface: Figma B · 20 cards (radius/md, border/default, bg/surface, e1, 20 padding). */
export const card = 'flex flex-col gap-4 rounded-md border border-line-default bg-surface p-5 shadow-e1';

/** "6260.00" → "৳6,260": groups the server's decimal string; never computes (rule 1). */
export function taka(decimal: string | null | undefined): string {
    if (decimal === null || decimal === undefined || decimal === '') return '—';
    const negative = decimal.startsWith('-');
    const [whole = '', fraction = ''] = (negative ? decimal.slice(1) : decimal).split('.');
    const trimmed = fraction.replace(/0+$/, '');
    return `${negative ? '−' : ''}${TAKA}${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}${trimmed ? `.${trimmed}` : ''}`;
}

/** "15 Jun 2026, 12:00 am" in Dhaka time, Western digits (rules 11, 36). */
export function dhakaDateTime(iso: string | null | undefined, locale: string): string {
    if (!iso) return '—';
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', {
        timeZone: 'Asia/Dhaka',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
        numberingSystem: 'latn',
    }).format(new Date(iso));
}

export function dhakaDay(iso: string | null | undefined, locale: string): string {
    if (!iso) return '—';
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { timeZone: 'Asia/Dhaka', day: 'numeric', month: 'short', numberingSystem: 'latn' }).format(new Date(iso));
}

/** ISO instant → the `datetime-local` value as Dhaka wall time ("2026-06-15T00:00"). */
export function toDhakaInput(iso: string | null | undefined): string {
    if (!iso) return '';
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Dhaka',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(new Date(iso));
    const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '00';
    return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
}

/** A Dhaka wall time from the form → ISO with the +06:00 offset the API reads as Dhaka. */
export function fromDhakaInput(value: string): string | null {
    return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value) ? `${value}:00+06:00` : null;
}

/** Today in Dhaka as Y-m-d, `offsetDays` back or forward. */
export function dhakaDate(offsetDays = 0): string {
    const now = new Date(Date.now() + offsetDays * 86_400_000);
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
}

/** Decimal input → the API's decimal string ("1,500" → "1500"); empty → null. */
export function money(value: string): string | null {
    const v = value.trim().replace(/,/g, '');
    return v === '' ? null : v;
}

export function intOrNull(value: string): number | null {
    const v = value.trim();
    return v === '' || Number.isNaN(Number(v)) ? null : Math.trunc(Number(v));
}

export function crumbs(t: T, page: MessageKey) {
    return [{ label: t('mkt.crumb.grow') }, { label: t('mkt.crumb.section'), to: '/marketing/coupons' }, { label: t(page) }];
}

/** The section's tab strip (Figma B · 20 tabs, with abandoned carts from B · 21). */
export function MarketingTabs() {
    const { t } = useI18n();
    return (
        <TabList label={t('mkt.tabs')}>
            <TabLink to="/marketing/coupons" label={t('mkt.tab.coupons')} />
            <TabLink to="/marketing/promotions" label={t('mkt.tab.promotions')} />
            <TabLink to="/marketing/flash-sales" label={t('mkt.tab.flash')} />
            <TabLink to="/marketing/abandoned-carts" label={t('mkt.tab.abandoned')} />
        </TabList>
    );
}

/** A labelled switch row (Figma B · 20 "Valid with cash on delivery" rows). */
export function ToggleRow({ label, hint, checked, onChange, disabled }: { label: string; hint?: string; checked: boolean; onChange: (next: boolean) => void; disabled?: boolean }) {
    return (
        <div className="flex items-start justify-between gap-4">
            <div className="flex min-w-0 flex-col gap-0.5">
                <span className="t-admin-body text-fg-primary">{label}</span>
                {hint && <span className="t-admin-caption text-fg-secondary">{hint}</span>}
            </div>
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                aria-label={label}
                disabled={disabled}
                onClick={() => onChange(!checked)}
                className={`relative mt-0.5 inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition-colors duration-150 ease-standard disabled:cursor-not-allowed disabled:opacity-60 ${checked ? 'bg-brand' : 'bg-line-strong'}`}
            >
                <span aria-hidden className={`size-4 rounded-full bg-surface shadow-e1 transition-transform duration-150 ease-standard ${checked ? 'translate-x-4' : 'translate-x-0'}`} />
            </button>
        </div>
    );
}

/** The big selectable type tiles of B · 20 ("% off", "Fixed ৳ off", "Free delivery", "Buy X get Y"). */
export function TypeTiles<V extends string>({ label, options, value, onChange }: { label: string; options: { value: V; label: string; icon: ComponentType<IconProps> }[]; value: V; onChange: (v: V) => void }) {
    return (
        <div role="radiogroup" aria-label={label} className={`grid grid-cols-2 gap-2 ${options.length === 3 ? 'sm:grid-cols-3' : options.length >= 5 ? 'sm:grid-cols-3 xl:grid-cols-5' : 'sm:grid-cols-4'}`}>
            {options.map((o) => {
                const active = o.value === value;
                const Icon = o.icon;
                return (
                    <button
                        key={o.value}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        onClick={() => onChange(o.value)}
                        className={`flex h-18 flex-col items-center justify-center gap-1.5 rounded-sm px-2 text-center t-admin-body-strong text-fg-primary ${
                            active ? 'border-2 border-line-brand bg-brand-subtle' : 'border border-line-default bg-surface hover:bg-subtle'
                        }`}
                    >
                        <Icon size={20} aria-hidden className={active ? 'text-fg-brand' : 'text-fg-primary'} />
                        {o.label}
                    </button>
                );
            })}
        </div>
    );
}

export function promotionTone(status: PromotionStatus): StatusTone {
    return status === 'running' ? 'success' : status === 'scheduled' ? 'info' : 'neutral';
}

export function PromotionStatusChip({ status }: { status: PromotionStatus }) {
    const { t } = useI18n();
    return <StatusChip label={t(`mkt.status.${status}` as MessageKey)} tone={promotionTone(status)} />;
}

/** Coupon status at render time from its server fields: inactive, scheduled, ended or running. */
export function couponStatus(c: Pick<Coupon, 'is_active' | 'starts_at' | 'ends_at'>, now = Date.now()): PromotionStatus {
    if (!c.is_active) return 'inactive';
    if (c.starts_at && Date.parse(c.starts_at) > now) return 'scheduled';
    if (c.ends_at && Date.parse(c.ends_at) <= now) return 'ended';
    return 'running';
}

/** "10% off orders over ৳1,500 · max ৳500" — the server's own figures, echoed. */
export function offerText(t: T, c: { type: Coupon['type']; value: string; min_order_amount?: string | null; max_discount?: string | null }): string {
    const head =
        c.type === 'percent'
            ? t('mkt.offer.percent', { value: c.value.replace(/\.00$/, '') })
            : c.type === 'fixed'
              ? t('mkt.offer.fixed', { value: taka(c.value) })
              : t('mkt.offer.free_shipping');
    const parts = [head];
    if (c.min_order_amount && Number(c.min_order_amount) > 0) parts[0] = `${head} ${t('mkt.offer.minimum', { amount: taka(c.min_order_amount) })}`;
    if (c.type === 'percent' && c.max_discount) parts.push(t('mkt.offer.max', { amount: taka(c.max_discount) }));
    return parts.join(' · ');
}

/** A search-and-add picker for products, categories or brands. Selected ids show as removable chips. */
export function IdPicker({ kind, label, value, onChange, error }: { kind: PickKind; label: string; value: number[]; onChange: (ids: number[]) => void; error?: string }) {
    const { t, locale } = useI18n();
    const [q, setQ] = useState('');
    const [names, setNames] = useState<Record<number, string>>({});
    const results = usePick(kind, q);
    const name = (item: PickItem) => (locale === 'bn' ? (item.name_bn ?? item.name) : item.name);
    const add = (item: PickItem) => {
        setNames((n) => ({ ...n, [item.id]: name(item) }));
        if (!value.includes(item.id)) onChange([...value, item.id]);
        setQ('');
    };
    const kindLabel = t(`mkt.scope.kind.${kind}` as MessageKey);

    return (
        <Field label={label} hint={t('mkt.scope.searchHint')} error={error}>
            {({ id, describedBy, invalid }) => (
                <div className="flex flex-col gap-2">
                    {value.length > 0 && (
                        <ul className="flex flex-wrap gap-1.5" aria-label={label}>
                            {value.map((v) => (
                                <li key={v} className="inline-flex h-7 items-center gap-1 rounded-sm border border-line-default bg-subtle ps-2 pe-1 t-admin-caption text-fg-primary">
                                    {names[v] ?? `#${v}`}
                                    <button type="button" className="inline-flex size-5 items-center justify-center rounded-xs hover:bg-surface" aria-label={t('mkt.scope.remove', { name: names[v] ?? `#${v}` })} onClick={() => onChange(value.filter((x) => x !== v))}>
                                        <XIcon size={12} aria-hidden />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    <div className="relative">
                        <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('mkt.scope.pick', { kind: kindLabel })} autoComplete="off" />
                        {q.trim().length >= 2 && results.data && (
                            <ul role="listbox" aria-label={t('mkt.scope.pick', { kind: kindLabel })} className="absolute inset-x-0 top-full z-10 mt-1 max-h-60 overflow-auto rounded-sm border border-line-default bg-surface py-1 shadow-e2">
                                {results.data.length === 0 && <li className="px-3 py-2 t-admin-caption text-fg-secondary">{t('mkt.scope.noMatch')}</li>}
                                {results.data.map((item) => (
                                    <li key={item.id} role="option" aria-selected={value.includes(item.id)}>
                                        <button type="button" onClick={() => add(item)} className="flex w-full items-center justify-between gap-2 px-3 py-2 text-start t-admin-body text-fg-primary hover:bg-subtle">
                                            {name(item)}
                                            {value.includes(item.id) && <CheckIcon size={14} aria-hidden className="text-fg-brand" />}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            )}
        </Field>
    );
}

export type ScopeDraft = {
    applies_to: ScopeType;
    ids: number[];
    exclude_product_ids: number[];
    exclude_category_ids: number[];
    exclude_brand_ids: number[];
};

export const emptyScope: ScopeDraft = { applies_to: 'all', ids: [], exclude_product_ids: [], exclude_category_ids: [], exclude_brand_ids: [] };

/** "Applies to" + the picker for its ids + exclusions — shared by coupons and promotions. */
export function ScopeEditor({ value, onChange, errors }: { value: ScopeDraft; onChange: (next: ScopeDraft) => void; errors?: { ids?: string } }) {
    const { t } = useI18n();
    const set = (patch: Partial<ScopeDraft>) => onChange({ ...value, ...patch });
    return (
        <div className="flex flex-col gap-4">
            <Field label={t('mkt.coupon.appliesTo')}>
                {({ id, describedBy }) => (
                    <Select id={id} aria-describedby={describedBy} value={value.applies_to} onChange={(e) => set({ applies_to: e.target.value as ScopeType, ids: [] })}>
                        {scopeTypes.map((s) => (
                            <option key={s} value={s}>
                                {t(`mkt.scope.${s}` as MessageKey)}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>
            {value.applies_to !== 'all' && <IdPicker kind={value.applies_to} label={t(`mkt.scope.${value.applies_to}` as MessageKey)} value={value.ids} onChange={(ids) => set({ ids })} error={errors?.ids} />}
            <details className="group rounded-sm border border-line-default p-3">
                <summary className="cursor-pointer t-admin-body-strong text-fg-primary">{t('mkt.scope.excludes')}</summary>
                <div className="mt-3 flex flex-col gap-4">
                    <IdPicker kind="products" label={t('mkt.scope.excludeProducts')} value={value.exclude_product_ids} onChange={(ids) => set({ exclude_product_ids: ids })} />
                    <IdPicker kind="categories" label={t('mkt.scope.excludeCategories')} value={value.exclude_category_ids} onChange={(ids) => set({ exclude_category_ids: ids })} />
                    <IdPicker kind="brands" label={t('mkt.scope.excludeBrands')} value={value.exclude_brand_ids} onChange={(ids) => set({ exclude_brand_ids: ids })} />
                </div>
            </details>
        </div>
    );
}

/** A two-column builder: the form cards left, the sticky summary/report column right (B · 20). */
export function BuilderLayout({ main, aside }: { main: ReactNode; aside: ReactNode }) {
    return (
        <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_--spacing(94)]">
            <div className="flex min-w-0 flex-col gap-6">{main}</div>
            <aside className="flex flex-col gap-6 xl:sticky xl:top-6">{aside}</aside>
        </div>
    );
}

/** The summary card of B · 20: a dashed accent ticket with the code, then check-marked facts. */
export function SummaryCard({ ticket, ticketSub, facts, note }: { ticket: string; ticketSub: string; facts: string[]; note?: string }) {
    const { t } = useI18n();
    return (
        <section className={card} aria-labelledby="mkt-summary">
            <h2 id="mkt-summary" className="t-admin-section text-fg-primary">
                {t('mkt.summary.title')}
            </h2>
            <div className="flex flex-col items-center gap-1 rounded-sm border border-dashed border-accent bg-accent-subtle px-4 py-5 text-center">
                <span className="t-admin-num-lg break-all text-fg-primary">{ticket}</span>
                <span className="t-admin-caption text-fg-primary">{ticketSub}</span>
            </div>
            <ul className="flex flex-col gap-3">
                {facts.map((f) => (
                    <li key={f} className="flex items-start gap-2 t-admin-body text-fg-primary">
                        <CheckIcon size={16} aria-hidden className="mt-0.5 shrink-0 text-feedback-success" />
                        {f}
                    </li>
                ))}
            </ul>
            {note && <p className="t-admin-caption text-fg-secondary">{note}</p>}
        </section>
    );
}

function Metric({ label, value, sub }: { label: string; value: string; sub?: string }) {
    return (
        <div className="flex flex-col gap-0.5">
            <div className="flex items-baseline justify-between gap-3">
                <span className="t-admin-body text-fg-secondary">{label}</span>
                <span className="t-admin-body-strong tabular text-fg-primary">{value}</span>
            </div>
            {sub && <span className="t-admin-caption text-fg-tertiary">{sub}</span>}
        </div>
    );
}

/** The usage report (coupons and promotions): server summary + the latest uses with masked phones. */
export function UsageReport({ summary, batch, recent }: { summary: UsageSummary; batch?: UsageSummary | null; recent: UsageRow[] }) {
    const { t, locale } = useI18n();
    return (
        <section className={card} aria-labelledby="mkt-report">
            <h2 id="mkt-report" className="t-admin-section text-fg-primary">
                {t('mkt.report.title')}
            </h2>
            <div className="flex flex-col gap-3">
                <Metric label={t('mkt.report.orders')} value={String(summary.orders)} />
                <Metric label={t('mkt.report.discount')} value={taka(summary.discount_total)} />
                <Metric label={t('mkt.report.gross')} value={taka(summary.gross_sales)} />
                <Metric label={t('mkt.report.delivered')} value={taka(summary.delivered_revenue)} sub={t('mkt.report.deliveredHint', { count: summary.delivered_orders })} />
            </div>
            {batch && (
                <div className="flex flex-col gap-3 border-t border-line-default pt-3">
                    <p className="t-admin-overline text-fg-secondary">{t('mkt.report.batch')}</p>
                    <Metric label={t('mkt.report.orders')} value={String(batch.orders)} />
                    <Metric label={t('mkt.report.discount')} value={taka(batch.discount_total)} />
                    <Metric label={t('mkt.report.delivered')} value={taka(batch.delivered_revenue)} />
                </div>
            )}
            <div className="flex flex-col gap-2 border-t border-line-default pt-3">
                <p className="t-admin-overline text-fg-secondary">{t('mkt.report.recent')}</p>
                {recent.length === 0 ? (
                    <p className="t-admin-caption text-fg-secondary">{t('mkt.report.empty')}</p>
                ) : (
                    <table className={dataTable.table}>
                        <thead>
                            <tr>
                                <th className={dataTable.th}>{t('mkt.report.col.order')}</th>
                                <th className={dataTable.th}>{t('mkt.report.col.phone')}</th>
                                <th className={`${dataTable.th} text-end`}>{t('mkt.report.col.discount')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {recent.slice(0, 10).map((r) => (
                                <tr key={r.order_id} className="border-t border-line-default">
                                    <td className="py-2 pe-2">
                                        <span className="block t-admin-mono-sm text-fg-primary">{r.order_number}</span>
                                        <span className="block t-admin-caption text-fg-tertiary">{dhakaDay(r.used_at, locale)}</span>
                                    </td>
                                    <td className="py-2 pe-2 t-admin-caption tabular text-fg-secondary">{r.phone}</td>
                                    <td className="py-2 text-end t-admin-body tabular text-fg-primary">{taka(r.discount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </section>
    );
}

/** Pull field errors from a 422 problem into a flat map. */
export function fieldErrors(errors: { field: string; message: string }[] | undefined): Record<string, string> {
    return Object.fromEntries((errors ?? []).map((e) => [e.field, e.message]));
}
