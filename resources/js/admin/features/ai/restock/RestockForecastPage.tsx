import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { ArrowsClockwiseIcon, PlusCircleIcon, ShoppingCartIcon, WarningIcon, XCircleIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { formatCount } from '@/lib/money';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { useSavePurchase } from '@/features/purchasing/api';
import { AiByline, AiErrorNotice, AiPanel, modelLabel } from '@/features/ai/AiNotice';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button, buttonClass } from '@/components/ui/Button';
import { dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { KpiCard } from '@/components/ui/KpiCard';
import { QueryError } from '@/components/ui/QueryError';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { StatusChip } from '@/components/ui/StatusChip';
import { TakaAmount } from '@/components/ui/TakaAmount';
import { useToast } from '@/components/ui/Toast';
import { horizons, useRestockForecast, useRestockNote, type Horizon, type RestockForecast, type RestockRow } from './api';

const WHOLE = /^\d{1,7}$/;

/**
 * Inventory › Restock forecast (Figma "AI for growth" 540:83487). The forecast — rates, run-out
 * days, lead times, suggested quantities and money — is the server's arithmetic (rule 1); the AI
 * only writes the "In plain words" note from those figures. Suggest only: "Create draft purchase
 * order" posts the selected rows to the existing `POST /purchases` as a draft, and nothing is
 * ordered until someone places that PO.
 */
export function RestockForecastPage() {
    const { t } = useI18n();
    const [params, setParams] = useSearchParams();
    const raw = params.get('horizon');
    const horizon: Horizon = horizons.includes(raw as Horizon) ? (raw as Horizon) : '14';
    const forecast = useRestockForecast(horizon);
    const data = forecast.data;

    return (
        <section className="flex flex-col gap-6 pb-28 max-sm:gap-4">
            <ContentHeader
                breadcrumb={[{ label: t('inventory.crumb'), to: '/inventory' }, { label: t('aiRestock.crumb') }]}
                title={t('aiRestock.title')}
                subtitle={t('aiRestock.subtitle')}
                actions={
                    <SegmentedControl
                        label={t('aiRestock.horizon.label')}
                        options={horizons.map((h) => ({ value: h, label: t(`aiRestock.horizon.${h}`) }))}
                        value={horizon}
                        onChange={(h) => setParams(h === '14' ? {} : { horizon: h }, { replace: true })}
                    />
                }
            />

            {forecast.isError ? (
                <QueryError error={forecast.error} onRetry={() => void forecast.refetch()} />
            ) : data ? (
                <>
                    <Kpis data={data} />
                    <Note data={data} horizon={horizon} />
                    {/* Keyed by the figures' identity, so edits never carry over to another horizon. */}
                    <Rows key={`${data.horizon}:${data.today}`} data={data} />
                    <p className="t-admin-caption text-fg-secondary">{t('aiRestock.footnote', { days: String(data.horizon) })}</p>
                </>
            ) : (
                <div aria-hidden className="grid gap-3 sm:gap-4 md:grid-cols-3">
                    {[0, 1, 2].map((i) => (
                        <div key={i} className="h-28 rounded-md bg-skeleton motion-safe:animate-pulse" />
                    ))}
                </div>
            )}
        </section>
    );
}

function Kpis({ data }: { data: RestockForecast }) {
    const { t } = useI18n();
    const k = data.kpis;
    return (
        <div className="grid gap-3 sm:gap-4 md:grid-cols-3">
            <KpiCard
                icon={XCircleIcon}
                tone="danger"
                label={t('aiRestock.kpi.runningOut', { days: String(data.horizon) })}
                value={<span className="t-admin-num-lg">{k.running_out === 1 ? t('aiRestock.kpi.productsOne') : t('aiRestock.kpi.products', { count: formatCount(k.running_out) })}</span>}
                hint={t('aiRestock.kpi.beforeArrival', { count: formatCount(k.before_arrival) })}
            />
            <KpiCard
                icon={WarningIcon}
                tone="warning"
                label={t('aiRestock.kpi.atRisk')}
                value={<TakaAmount amount={k.sales_at_risk} places="auto" signClass="t-admin-currency-num-lg" />}
                hint={t('aiRestock.kpi.atRiskHint')}
            />
            <KpiCard
                icon={ShoppingCartIcon}
                tone="brand"
                label={t('aiRestock.kpi.suggested')}
                value={k.suggested_order_value === null ? <span className="t-admin-num-lg">—</span> : <TakaAmount amount={k.suggested_order_value} places="auto" signClass="t-admin-currency-num-lg" />}
                hint={k.suggested_order_value === null ? t('aiRestock.kpi.costHidden') : t('aiRestock.kpi.suggestedHint')}
            />
        </div>
    );
}

function Note({ data, horizon }: { data: RestockForecast; horizon: Horizon }) {
    const { t, locale } = useI18n();
    const write = useRestockNote(horizon);
    const note = data.note;
    const omitted = write.data?.omitted ?? null;
    const run = (fresh: boolean) => write.mutate(fresh ? { horizon, fresh } : { horizon });
    const when = (iso: string) =>
        new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { timeZone: 'Asia/Dhaka', day: '2-digit', month: 'short', hour: 'numeric', minute: '2-digit', hour12: true, numberingSystem: 'latn' }).format(new Date(iso));

    let body;
    if (data.note_blocked === 'figures_not_shared') {
        body = (
            <p className="t-admin-body text-fg-primary">
                {t('aiRestock.note.figuresOff')}{' '}
                <Link to="/integrations/ai" className="text-fg-brand underline">
                    {t('aiRestock.note.figuresOffLink')}
                </Link>
            </p>
        );
    } else if (data.note_blocked === 'nothing_to_order') {
        body = <p className="t-admin-body text-fg-primary">{t('aiRestock.note.nothing')}</p>;
    } else if (write.isError) {
        body = <AiErrorNotice error={write.error} onRetry={() => run(false)} />;
    } else if (note && !write.isPending) {
        body = (
            <>
                <p className="t-admin-body text-fg-primary">{note.sentences.join(' ')}</p>
                <AiByline model={modelLabel(note.model)} />
            </>
        );
    } else {
        body = (
            <div className="flex flex-wrap items-center gap-3">
                <p className="min-w-0 flex-1 t-admin-body text-fg-primary">{omitted === 'unverified_figures' ? t('aiRestock.note.unverified') : t('aiRestock.note.empty')}</p>
                <Button variant="outline" size="sm" disabled={write.isPending} onClick={() => run(false)}>
                    {write.isPending ? t('aiRestock.note.writing') : t('aiRestock.note.write')}
                </Button>
            </div>
        );
    }

    return (
        <AiPanel
            title={t('aiRestock.note.title')}
            meta={
                note && !write.isPending && data.note_blocked === null ? (
                    <span className="flex items-center gap-2 t-admin-caption text-fg-secondary">
                        <span className="tabular">{when(note.generated_at)}</span>
                        <button type="button" onClick={() => run(true)} className={buttonClass('ghost', 'sm')}>
                            <ArrowsClockwiseIcon size={14} aria-hidden />
                            {t('aiRestock.note.rewrite')}
                        </button>
                    </span>
                ) : undefined
            }
        >
            {body}
        </AiPanel>
    );
}

type Problem = 'none' | 'mixed' | 'noSupplier' | 'costHidden' | 'badQty' | 'noPermission' | null;

function Rows({ data }: { data: RestockForecast }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const navigate = useNavigate();
    const { data: me } = useMe();
    const canCreate = can(me, 'purchasing.create');
    const save = useSavePurchase(null);
    const [selected, setSelected] = useState<ReadonlySet<number>>(new Set());
    // Edited order quantities; an untouched row orders the server's suggestion.
    const [edits, setEdits] = useState<Record<number, string>>({});
    const qtyOf = (r: RestockRow) => edits[r.variant_id] ?? String(r.suggested_order);

    // A refetch of the same horizon (new sales) drops rows that no longer qualify from the selection.
    useEffect(() => {
        setSelected((s) => new Set([...s].filter((id) => data.rows.some((r) => r.variant_id === id))));
    }, [data.rows]);

    const picked = useMemo(() => data.rows.filter((r) => selected.has(r.variant_id)), [data.rows, selected]);
    const units = picked.reduce((sum, r) => sum + (WHOLE.test(qtyOf(r)) ? Number(qtyOf(r)) : 0), 0);
    const suppliers = new Set(picked.map((r) => r.supplier?.id ?? null));
    const supplier = picked[0]?.supplier ?? null;

    const problem: Problem = !canCreate
        ? 'noPermission'
        : picked.length === 0
          ? 'none'
          : suppliers.has(null)
            ? 'noSupplier'
            : suppliers.size > 1
              ? 'mixed'
              : picked.some((r) => r.last_unit_cost === null)
                ? 'costHidden'
                : picked.some((r) => !WHOLE.test(qtyOf(r)) || Number(qtyOf(r)) < 1)
                  ? 'badQty'
                  : null;

    const name = (r: RestockRow) => {
        const product = locale === 'bn' ? (r.product_name_bn ?? r.product_name) : r.product_name;
        const variant = locale === 'bn' ? (r.variant_name_bn ?? r.variant_name) : r.variant_name;
        return variant ? `${product} · ${variant}` : product;
    };

    function toggle(id: number) {
        setSelected((s) => {
            const next = new Set(s);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    }

    function create() {
        if (problem !== null || supplier === null) return;
        save.mutate(
            {
                supplier_id: supplier.id,
                order_date: data.today,
                lines: picked.map((r) => ({ variant_id: r.variant_id, quantity: Number(qtyOf(r)), unit_cost: r.last_unit_cost ?? '' })),
            },
            {
                onSuccess: (purchase) => {
                    notify(t('aiRestock.created'));
                    void navigate(`/purchasing/${purchase.id}`);
                },
                onError: (e) => notify(e instanceof ApiError && e.status === 422 ? (e.problem.errors?.[0]?.message ?? e.problem.title) : t('aiRestock.createFailed'), 'danger'),
            },
        );
    }

    if (data.rows.length === 0) {
        return <EmptyState reason="allClear" title={t('aiRestock.emptyTitle')} body={t('aiRestock.emptyBody')} />;
    }

    const all = picked.length === data.rows.length;
    const cell = `${dataTable.td} max-lg:h-auto max-lg:p-0`;
    const figure = `${cell} t-admin-num tabular lg:text-end`;
    const mobileLabel = 'block t-admin-caption text-fg-secondary lg:hidden';

    return (
        <>
            <div className={dataTable.card}>
                <table className={`${dataTable.table} max-lg:block`} aria-label={t('aiRestock.table.label')}>
                    <thead className={`${dataTable.head} max-lg:hidden`}>
                        <tr>
                            <th scope="col" className={`${dataTable.th} w-12`}>
                                <input
                                    type="checkbox"
                                    aria-label={t('aiRestock.col.selectAll')}
                                    checked={all}
                                    onChange={() => setSelected(all ? new Set() : new Set(data.rows.map((r) => r.variant_id)))}
                                    className="size-4 accent-brand"
                                />
                            </th>
                            <th scope="col" className={dataTable.th}>{t('aiRestock.col.product')}</th>
                            <th scope="col" className={`${dataTable.th} text-end`}>{t('aiRestock.col.inStock')}</th>
                            <th scope="col" className={`${dataTable.th} text-end`}>{t('aiRestock.col.perDay')}</th>
                            <th scope="col" className={`${dataTable.th} text-end`}>{t('aiRestock.col.runsOut')}</th>
                            <th scope="col" className={`${dataTable.th} text-end`}>{t('aiRestock.col.leadTime')}</th>
                            <th scope="col" className={`${dataTable.th} text-end`}>{t('aiRestock.col.order')}</th>
                            <th scope="col" className={dataTable.th}>{t('aiRestock.col.confidence')}</th>
                        </tr>
                    </thead>
                    <tbody className="max-lg:block">
                        {data.rows.map((r) => {
                            const label = name(r);
                            const days = r.runs_out_in_days;
                            return (
                                <tr key={r.variant_id} className={`${dataTable.row} max-lg:relative max-lg:grid max-lg:grid-cols-3 max-lg:gap-x-4 max-lg:gap-y-3 max-lg:p-4 max-lg:first:border-t-0`}>
                                    <td className={`${cell} max-lg:absolute max-lg:start-4 max-lg:top-4 lg:w-12`}>
                                        <input type="checkbox" aria-label={t('aiRestock.col.selectRow', { name: label })} checked={selected.has(r.variant_id)} onChange={() => toggle(r.variant_id)} className="size-4 accent-brand max-lg:mt-0.5" />
                                    </td>
                                    <td className={`${cell} max-lg:col-span-3 max-lg:ps-7`}>
                                        <span className="flex min-w-0 flex-col">
                                            <span className="t-admin-body-strong text-fg-primary">{label}</span>
                                            <span className="t-admin-caption text-fg-secondary">{r.sku}</span>
                                        </span>
                                    </td>
                                    <td className={figure}>
                                        <span className={mobileLabel}>{t('aiRestock.col.inStock')}</span>
                                        {formatCount(r.in_stock)}
                                        {r.open_po_quantity > 0 && <span className="block t-admin-caption text-fg-secondary">{t('aiRestock.onOrder', { count: formatCount(r.open_po_quantity) })}</span>}
                                    </td>
                                    <td className={figure}>
                                        <span className={mobileLabel}>{t('aiRestock.col.perDay')}</span>
                                        {r.sells_per_day}
                                    </td>
                                    <td className={`${cell} lg:text-end`}>
                                        <span className={mobileLabel}>{t('aiRestock.col.runsOut')}</span>
                                        <StatusChip
                                            tone={days <= 7 ? 'danger' : 'warning'}
                                            showIcon={false}
                                            label={days === 0 ? t('aiRestock.runsOut.today') : days === 1 ? t('aiRestock.runsOut.day') : t('aiRestock.runsOut.days', { days: formatCount(days) })}
                                        />
                                    </td>
                                    <td className={figure}>
                                        <span className={mobileLabel}>{t('aiRestock.col.leadTime')}</span>
                                        {t('aiRestock.lead.days', { days: formatCount(r.lead_time_days) })}
                                        {r.lead_time_estimated && (
                                            <span title={t('aiRestock.lead.estimatedTitle')} className="ms-1 t-admin-caption text-fg-secondary">
                                                ({t('aiRestock.lead.estimated')})
                                            </span>
                                        )}
                                    </td>
                                    <td className={`${cell} lg:text-end`}>
                                        <span className={mobileLabel}>{t('aiRestock.col.order')}</span>
                                        <input
                                            type="number"
                                            inputMode="numeric"
                                            min={0}
                                            step={1}
                                            aria-label={t('aiRestock.orderQty', { name: label })}
                                            value={qtyOf(r)}
                                            onChange={(e) => setEdits((q) => ({ ...q, [r.variant_id]: e.target.value }))}
                                            className="h-9 w-20 rounded-sm border border-line-default bg-surface px-3 t-admin-num tabular text-fg-primary outline-none focus-visible:border-line-focus focus-visible:ring-1 focus-visible:ring-inset focus-visible:ring-line-focus"
                                        />
                                    </td>
                                    <td className={`${cell} t-admin-caption text-fg-secondary`}>
                                        <span className={mobileLabel}>{t('aiRestock.col.confidence')}</span>
                                        {t(`aiRestock.confidence.${r.confidence}`)}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            <div className="sticky bottom-0 z-10 -mx-4 border-t border-line-default bg-surface px-4 py-3 shadow-e2 md:mx-0 md:rounded-md md:border">
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <p className="t-admin-body-strong text-fg-primary tabular" aria-live="polite">
                        {picked.length === 0 ? t('aiRestock.bar.none') : t('aiRestock.bar.selected', { count: formatCount(picked.length), units: formatCount(units) })}
                    </p>
                    <p className="min-w-0 flex-1 t-admin-caption text-fg-secondary">
                        {problem === null && supplier
                            ? t('aiRestock.bar.supplier', { name: supplier.name })
                            : problem !== null && problem !== 'none'
                              ? t(`aiRestock.bar.${problem}`)
                              : null}
                    </p>
                    <Button className="max-sm:w-full" disabled={problem !== null || save.isPending} onClick={create}>
                        <PlusCircleIcon size={16} aria-hidden />
                        {save.isPending ? t('aiRestock.creating') : t('aiRestock.create')}
                    </Button>
                </div>
            </div>
        </>
    );
}
