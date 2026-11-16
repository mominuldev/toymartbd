import { useMemo, useState } from 'react';
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router';
import { ClockCounterClockwiseIcon, ListChecksIcon, MopedIcon, UploadSimpleIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button } from '@/components/ui/Button';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { KpiCard } from '@/components/ui/KpiCard';
import { QueryError } from '@/components/ui/QueryError';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Select } from '@/components/ui/Select';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { ApiError } from '@/lib/api';
import { useReceivables } from '../api';
import { FinanceTabs, KpiMoney, TAKA, TakaFixed, dhakaDate, formatCount, formatFixed, shortDate } from '../parts';
import { settlementViews, useImportSettlement, useSettlements, type ManualLine, type Provider, type Settlement, type SettlementView } from './api';

export const problemText = (e: unknown, fallback: string) => (e instanceof ApiError ? (e.problem.errors?.[0]?.message ?? e.problem.detail ?? e.problem.title) : fallback);

/** "1 – 12 Nov" for a statement's period (Dhaka dates as given, Western digits). */
export function periodText(s: Settlement, locale: string): string | null {
    if (!s.period_start || !s.period_end) return null;
    const fmt = (ymd: string, opts: Intl.DateTimeFormatOptions) =>
        new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { timeZone: 'UTC', numberingSystem: 'latn', ...opts }).format(new Date(`${ymd}T00:00:00Z`));
    const sameMonth = s.period_start.slice(0, 7) === s.period_end.slice(0, 7);
    return `${fmt(s.period_start, sameMonth ? { day: 'numeric' } : { day: 'numeric', month: 'short' })} – ${fmt(s.period_end, { day: 'numeric', month: 'short' })}`;
}

export const providerName = (s: Settlement, locale: string) => (locale === 'bn' && s.provider.name_bn ? s.provider.name_bn : s.provider.name);

/**
 * COD settlements (Figma B · 32 COD settlements 426:72979, DATABASE-DESIGN §4.5): three KPI cards —
 * with couriers now and owed for over 30 days (the server's receivables), statements to review
 * (`meta.disputed_count`, a shortcut to that view) — the All / To confirm / Need a decision /
 * Reconciled view and courier filter (both server allowlists), and the statements table. A
 * statement opens on its own page, line by line (426:73739). Nothing is booked until "Reconcile".
 */
export function SettlementsPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const navigate = useNavigate();
    const [params, setParams] = useSearchParams();
    const statusParam = params.get('status') ?? '';
    const status: SettlementView = settlementViews.includes(statusParam as SettlementView) ? (statusParam as SettlementView) : '';
    const courier = /^[a-z0-9_-]+$/.test(params.get('courier') ?? '') ? params.get('courier')! : '';
    const list = useSettlements({ status, provider: courier });
    const receivables = useReceivables();
    const rows = useMemo(() => list.data?.pages.flatMap((p) => p.data) ?? [], [list.data]);
    const meta = list.data?.pages[0]?.meta;
    const providers = meta?.providers ?? [];
    const [importOpen, setImportOpen] = useState(false);
    const reconcile = can(me, 'finance.reconcile_cod');

    // The old "?id=" link opened a statement under the list; it now has its own page.
    const legacyId = params.get('id');
    if (legacyId && /^\d+$/.test(legacyId)) return <Navigate to={`/finance/settlements/${legacyId}`} replace />;

    const setParam = (key: string, value: string) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                if (value) next.set(key, value);
                else next.delete(key);
                return next;
            },
            { replace: true },
        );
    const end = dataTable.th.replace('[:where(&)]:text-start', 'text-end');
    const r = receivables.data;

    return (
        <div className="flex flex-col gap-6">
            <ContentHeader
                title={t('settlements.title')}
                breadcrumb={[{ label: t('settlements.crumb.money') }, { label: t('settlements.crumb.finance') }]}
                subtitle={t('finUi.set.subtitle')}
                actions={
                    reconcile && (
                        <Button onClick={() => setImportOpen(true)}>
                            <UploadSimpleIcon size={16} aria-hidden />
                            {t('settlements.upload')}
                        </Button>
                    )
                }
            />
            <FinanceTabs />
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <KpiCard
                    icon={MopedIcon}
                    tone="brand"
                    label={t('finUi.set.stat.with')}
                    value={r ? <KpiMoney amount={r.outstanding} /> : <span className="t-admin-num-lg">—</span>}
                    hint={r ? t('finUi.set.stat.withHint', { count: formatCount(r.open_orders) }) : ''}
                />
                <KpiCard
                    icon={ClockCounterClockwiseIcon}
                    tone="danger"
                    label={t('finUi.set.stat.old')}
                    value={r ? <KpiMoney amount={r.aging['31_plus']} /> : <span className="t-admin-num-lg">—</span>}
                    hint={t('finUi.set.stat.oldHint')}
                    onClick={() => navigate('/finance/ledger')}
                />
                <KpiCard
                    icon={ListChecksIcon}
                    tone="warning"
                    label={t('finUi.set.stat.review')}
                    value={<span className="t-admin-num-lg">{meta?.disputed_count === undefined ? '—' : formatCount(meta.disputed_count)}</span>}
                    hint={t('finUi.set.stat.reviewHint')}
                    onClick={() => setParam('status', status === 'disputed' ? '' : 'disputed')}
                    active={status === 'disputed'}
                />
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <SegmentedControl
                        label={t('finUi.set.show')}
                        options={settlementViews.map((value) => ({ value, label: t(`finUi.set.show.${value || 'all'}` as MessageKey) }))}
                        value={status}
                        onChange={(value) => setParam('status', value)}
                        className="h-9"
                    />
                    <label className="flex">
                        <span className="sr-only">{t('finUi.set.courier')}</span>
                        <Select value={courier} onChange={(e) => setParam('courier', e.target.value)} className="w-45">
                            <option value="">{t('finUi.set.courierAll')}</option>
                            {providers.map((p) => (
                                <option key={p.code} value={p.code}>
                                    {locale === 'bn' && p.name_bn ? p.name_bn : p.name}
                                </option>
                            ))}
                        </Select>
                    </label>
                </div>
                <p className="t-admin-caption text-fg-secondary">{t('finUi.set.caption')}</p>
            </div>

            {list.isError ? (
                <QueryError error={list.error} onRetry={() => void list.refetch()} />
            ) : list.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : rows.length === 0 && (status || courier) ? (
                <EmptyState title={t('finUi.set.noMatch')} body={t('finUi.set.noMatchBody')} />
            ) : rows.length === 0 ? (
                <EmptyState
                    title={t('settlements.empty.title')}
                    body={t('settlements.empty.body')}
                    actions={reconcile && <Button onClick={() => setImportOpen(true)}>{t('settlements.upload')}</Button>}
                />
            ) : (
                <section className={dataTable.card}>
                    <div className="overflow-x-auto">
                        <table className={dataTable.table}>
                            <caption className="sr-only">{t('settlements.list')}</caption>
                            <thead className={dataTable.head}>
                                <tr className="border-b border-line-default">
                                    <th scope="col" className={dataTable.th}>{t('settlements.col.statement')}</th>
                                    <th scope="col" className={dataTable.th}>{t('settlements.col.courier')}</th>
                                    <th scope="col" className={dataTable.th}>{t('settlements.col.period')}</th>
                                    <th scope="col" className={end}>{t('settlements.col.cod')}</th>
                                    <th scope="col" className={end}>{t('finUi.set.col.kept')}</th>
                                    <th scope="col" className={end}>{t('settlements.col.net')}</th>
                                    <th scope="col" className={dataTable.th}>{t('settlements.col.received')}</th>
                                    <th scope="col" className={dataTable.th}>{t('settlements.col.status')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((s) => (
                                    <tr key={s.id} className={`${dataTable.row} ${s.status === 'disputed' ? 'bg-brand-subtle hover:bg-brand-subtle' : ''}`}>
                                        <td className={`${dataTable.td} h-17`}>
                                            <Link to={`/finance/settlements/${s.id}`} aria-label={t('finUi.set.open', { reference: s.reference })} className="t-admin-mono text-fg-link hover:underline">
                                                {s.reference}
                                            </Link>
                                            <p className="t-admin-caption text-fg-secondary">
                                                {s.imported_by ? t('finUi.set.by', { source: t(`settlements.source.${s.source}` as MessageKey), who: s.imported_by }) : t(`settlements.source.${s.source}` as MessageKey)}
                                            </p>
                                        </td>
                                        <td className={`${dataTable.td} t-admin-body`}>{providerName(s, locale)}</td>
                                        <td className={`${dataTable.td} t-admin-body tabular whitespace-nowrap`}>{periodText(s, locale) ?? '—'}</td>
                                        <td className={`${dataTable.td} t-admin-num text-end`}>
                                            <TakaFixed amount={s.totals.cod_collected} />
                                        </td>
                                        <td className={`${dataTable.td} text-end`}>
                                            <span className="t-admin-num">
                                                <TakaFixed amount={s.totals.delivery_charges} />
                                            </span>
                                            <Deductions s={s} />
                                        </td>
                                        <td className={`${dataTable.td} t-admin-num text-end`}>
                                            <TakaFixed amount={s.totals.net_received} />
                                        </td>
                                        <td className={`${dataTable.td} t-admin-body tabular whitespace-nowrap`}>{shortDate(s.received_at, locale)}</td>
                                        <td className={dataTable.td}>
                                            <SettlementStatus settlement={s} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <DataTableFooter summary={t('finUi.set.showing', { count: formatCount(rows.length) })}>
                        {list.hasNextPage && (
                            <Button variant="ghost" size="sm" disabled={list.isFetchingNextPage} onClick={() => void list.fetchNextPage()}>
                                {t('common.loadMore')}
                            </Button>
                        )}
                    </DataTableFooter>
                </section>
            )}
            {importOpen && <ImportDialog providers={providers} onClose={() => setImportOpen(false)} onImported={(id) => (setImportOpen(false), navigate(`/finance/settlements/${id}`))} />}
        </div>
    );
}

/** The courier's other deductions under its delivery charge, as the server totalled them — never added here (rule 1). */
export function Deductions({ s }: { s: Settlement }) {
    const { t } = useI18n();
    const parts = [
        s.totals.cod_fees !== '0.00' && `${t('finUi.set.codFee')} ${TAKA}${formatFixed(s.totals.cod_fees)}`,
        s.totals.return_charges !== '0.00' && `${t('finUi.set.returns')} ${TAKA}${formatFixed(s.totals.return_charges)}`,
    ].filter(Boolean);
    return parts.length > 0 ? <p className="t-admin-caption tabular text-fg-secondary">{parts.join(' · ')}</p> : null;
}

export function SettlementStatus({ settlement }: { settlement: Settlement }) {
    const { t } = useI18n();
    if (settlement.status === 'reconciled') return <StatusChip tone="success" label={t('settlements.status.reconciled')} />;
    if (settlement.status === 'disputed') return <StatusChip tone="anomaly" label={t('finUi.set.disputed', { count: formatCount(settlement.unresolved_count) })} />;
    return <StatusChip tone="info" label={t('settlements.status.received')} />;
}

type LineDraft = {
    consignment_id: string;
    cod_amount: string;
    delivery_charge: string;
    cod_fee: string;
};
const emptyLine: LineDraft = {
    consignment_id: '',
    cod_amount: '',
    delivery_charge: '',
    cod_fee: '',
};

/** Import a remittance: the courier portal's export (CSV/XLSX) or lines typed in by hand. */
function ImportDialog({ providers, onClose, onImported }: { providers: Provider[]; onClose: () => void; onImported: (id: number) => void }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const save = useImportSettlement();
    const [key] = useState(() => crypto.randomUUID());
    const [mode, setMode] = useState<'file' | 'manual'>('file');
    const [provider, setProvider] = useState(providers[0]?.code ?? '');
    const [reference, setReference] = useState('');
    const [receivedAt, setReceivedAt] = useState(dhakaDate(0));
    const [periodStart, setPeriodStart] = useState('');
    const [periodEnd, setPeriodEnd] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [lines, setLines] = useState<LineDraft[]>([{ ...emptyLine }]);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const submit = () => {
        const manual: ManualLine[] = lines
            .filter((l) => l.consignment_id.trim() !== '')
            .map((l) => ({
                consignment_id: l.consignment_id.trim(),
                cod_amount: l.cod_amount || '0',
                delivery_charge: l.delivery_charge || null,
                cod_fee: l.cod_fee || null,
            }));
        save.mutate(
            {
                input: {
                    provider,
                    reference,
                    received_at: receivedAt,
                    period_start: periodStart || null,
                    period_end: periodEnd || null,
                },
                file: mode === 'file' ? file : null,
                lines: manual,
                key,
            },
            {
                onSuccess: (s) => (
                    notify(
                        t('settlements.import.done', {
                            reference: s.reference,
                        }),
                    ),
                    onImported(s.id)
                ),
                onError: (e) => {
                    if (e instanceof ApiError && e.problem.errors) setErrors(Object.fromEntries(e.problem.errors.map((x) => [x.field, x.message])));
                    else notify(problemText(e, t('settlements.error')), 'danger');
                },
            },
        );
    };

    return (
        <div role="dialog" aria-modal="true" aria-labelledby="settlement-import" className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-canvas/80 p-4">
            <form
                className="flex w-full max-w-160 flex-col gap-4 rounded-lg border border-line-default bg-surface p-5 shadow-e4"
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
            >
                <h2 id="settlement-import" className="t-admin-section text-fg-primary">
                    {t('settlements.import.title')}
                </h2>
                <p className="t-admin-caption text-fg-secondary">{t('settlements.import.body')}</p>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label={t('settlements.import.provider')} error={errors.provider}>
                        {({ id, describedBy, invalid }) => (
                            <Select id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={provider} onChange={(e) => setProvider(e.target.value)} required>
                                {providers.map((p) => (
                                    <option key={p.code} value={p.code}>
                                        {locale === 'bn' && p.name_bn ? p.name_bn : p.name}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                    <Field label={t('settlements.import.reference')} hint={t('settlements.import.referenceHint')} error={errors.reference}>
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                aria-describedby={describedBy}
                                aria-invalid={invalid || undefined}
                                value={reference}
                                onChange={(e) => setReference(e.target.value)}
                                required
                                maxLength={120}
                                className="t-admin-mono"
                            />
                        )}
                    </Field>
                    <Field label={t('settlements.import.receivedAt')} error={errors.received_at}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} type="date" value={receivedAt} onChange={(e) => setReceivedAt(e.target.value)} required />
                        )}
                    </Field>
                    <div className="grid grid-cols-2 gap-2">
                        <Field label={t('settlements.import.periodStart')} optional error={errors.period_start}>
                            {({ id, describedBy, invalid }) => (
                                <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} type="date" value={periodStart} onChange={(e) => setPeriodStart(e.target.value)} />
                            )}
                        </Field>
                        <Field label={t('settlements.import.periodEnd')} optional error={errors.period_end}>
                            {({ id, describedBy, invalid }) => (
                                <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} type="date" value={periodEnd} onChange={(e) => setPeriodEnd(e.target.value)} />
                            )}
                        </Field>
                    </div>
                </div>
                <p className="t-admin-caption text-fg-tertiary">{t('settlements.import.periodHint')}</p>
                <div className="flex gap-1" role="radiogroup" aria-label={t('settlements.import.mode')}>
                    {(['file', 'manual'] as const).map((m) => (
                        <Button key={m} type="button" size="sm" variant={mode === m ? 'secondary' : 'ghost'} role="radio" aria-checked={mode === m} onClick={() => setMode(m)}>
                            {t(`settlements.import.mode.${m}` as MessageKey)}
                        </Button>
                    ))}
                </div>
                {mode === 'file' ? (
                    <Field label={t('settlements.import.file')} hint={t('settlements.import.fileHint')} error={errors.file}>
                        {({ id, describedBy, invalid }) => (
                            <input
                                id={id}
                                aria-describedby={describedBy}
                                aria-invalid={invalid || undefined}
                                type="file"
                                accept=".csv,.xlsx,text/csv"
                                onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                                className="t-admin-body text-fg-primary"
                            />
                        )}
                    </Field>
                ) : (
                    <div className="flex flex-col gap-2">
                        {lines.map((l, i) => (
                            <div key={i} className="grid grid-cols-4 gap-2">
                                {(['consignment_id', 'cod_amount', 'delivery_charge', 'cod_fee'] as const).map((field) => (
                                    <Input
                                        key={field}
                                        aria-label={`${t(`settlements.import.col.${field}` as MessageKey)} ${i + 1}`}
                                        placeholder={t(`settlements.import.col.${field}` as MessageKey)}
                                        value={l[field]}
                                        inputMode={field === 'consignment_id' ? undefined : 'decimal'}
                                        className={field === 'consignment_id' ? 't-admin-mono' : 'tabular'}
                                        onChange={(e) =>
                                            setLines((all) =>
                                                all.map((row, j) =>
                                                    j === i
                                                        ? {
                                                              ...row,
                                                              [field]: e.target.value,
                                                          }
                                                        : row,
                                                ),
                                            )
                                        }
                                    />
                                ))}
                            </div>
                        ))}
                        {errors['lines'] && <p className="t-admin-caption text-feedback-danger">{errors['lines']}</p>}
                        <Button type="button" variant="ghost" size="sm" onClick={() => setLines((all) => [...all, { ...emptyLine }])}>
                            {t('settlements.import.addLine')}
                        </Button>
                    </div>
                )}
                <div className="flex justify-end gap-2 border-t border-line-default pt-4">
                    <Button type="button" variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" disabled={save.isPending || !provider || !reference.trim() || (mode === 'file' && !file)}>
                        {t('settlements.import.submit')}
                    </Button>
                </div>
            </form>
        </div>
    );
}
