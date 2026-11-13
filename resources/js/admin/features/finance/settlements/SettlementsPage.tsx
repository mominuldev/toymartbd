import { useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { CheckCircleIcon, UploadSimpleIcon, WarningIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button } from '@/components/ui/Button';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { ApiError } from '@/lib/api';
import { useReceivables } from '../api';
import { FinanceTabs, StatCard, Taka, dhakaDate, formatCount, formatMoney, shortDate } from '../parts';
import { useConfirmSettlement, useImportSettlement, useReviewLine, useSettlement, useSettlements, type ManualLine, type Provider, type Settlement, type SettlementLine } from './api';

const problemText = (e: unknown, fallback: string) => (e instanceof ApiError ? (e.problem.errors?.[0]?.message ?? e.problem.detail ?? e.problem.title) : fallback);

/**
 * COD settlements (Figma B · 09 241:35325, DATABASE-DESIGN §4.5): statements on top, the
 * selected one line by line below — what the courier reported beside what our shipment
 * expected. Variances are flagged for a person (accept a short payment, or exclude); nothing is
 * adjusted automatically and nothing is booked until "Reconcile". The manual "Record COD
 * received" on the ledger page stays for one-offs.
 */
export function SettlementsPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const [params, setParams] = useSearchParams();
    const list = useSettlements();
    const receivables = useReceivables();
    const rows = useMemo(() => list.data?.pages.flatMap((p) => p.data) ?? [], [list.data]);
    const providers = list.data?.pages[0]?.meta.providers ?? [];
    const selectedId = Number(params.get('id')) || rows[0]?.id || null;
    const [importOpen, setImportOpen] = useState(false);
    const disputed = rows.filter((r) => r.status === 'disputed').length;
    const reconcile = can(me, 'finance.reconcile_cod');

    return (
        <div className="flex flex-col gap-6">
            <ContentHeader
                title={t('settlements.title')}
                breadcrumb={[{ label: t('settlements.crumb.money') }, { label: t('settlements.crumb.finance'), to: '/finance' }, { label: t('settlements.title') }]}
                subtitle={t('settlements.subtitle')}
                actions={
                    reconcile && (
                        <Button variant="outline" onClick={() => setImportOpen(true)}>
                            <UploadSimpleIcon size={18} aria-hidden />
                            {t('settlements.upload')}
                        </Button>
                    )
                }
            />
            <FinanceTabs />
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label={t('settlements.stat.withCourier')}
                    value={
                        receivables.data ? (
                            <span className="t-admin-num-xl">
                                <Taka amount={receivables.data.outstanding} signClass="t-admin-currency-num-lg" />
                            </span>
                        ) : (
                            '—'
                        )
                    }
                    hint={
                        receivables.data
                            ? t('settlements.stat.withCourierHint', {
                                  count: formatCount(receivables.data.open_orders),
                              })
                            : ''
                    }
                />
                <StatCard label={t('settlements.stat.review')} value={<span className="t-admin-num-xl">{formatCount(disputed)}</span>} hint={t('settlements.stat.reviewHint')} />
            </div>
            {list.isError ? (
                <QueryError error={list.error} onRetry={() => void list.refetch()} />
            ) : list.isSuccess && rows.length === 0 ? (
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
                                <tr>
                                    <th className={dataTable.th}>{t('settlements.col.statement')}</th>
                                    <th className={dataTable.th}>{t('settlements.col.courier')}</th>
                                    <th className={dataTable.th}>{t('settlements.col.period')}</th>
                                    <th className={`${dataTable.th} text-end`}>{t('settlements.col.cod')}</th>
                                    <th className={`${dataTable.th} text-end`}>{t('settlements.col.charges')}</th>
                                    <th className={`${dataTable.th} text-end`}>{t('settlements.col.net')}</th>
                                    <th className={dataTable.th}>{t('settlements.col.received')}</th>
                                    <th className={dataTable.th}>{t('settlements.col.status')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((s) => (
                                    <tr key={s.id} className={`${dataTable.row} cursor-pointer ${s.id === selectedId ? 'bg-brand-subtle' : ''}`} onClick={() => setParams({ id: String(s.id) })}>
                                        <td className={dataTable.td}>
                                            <Link to={`?id=${s.id}`} className="t-admin-mono text-fg-primary hover:underline">
                                                {s.reference}
                                            </Link>
                                            <p className="t-admin-caption text-fg-tertiary">{t(`settlements.source.${s.source}` as MessageKey)}</p>
                                        </td>
                                        <td className={`${dataTable.td} t-admin-body`}>{locale === 'bn' && s.provider.name_bn ? s.provider.name_bn : s.provider.name}</td>
                                        <td className={`${dataTable.td} t-admin-body tabular`}>
                                            {s.period_start && s.period_end ? `${shortDate(s.period_start, locale)}–${shortDate(s.period_end, locale)}` : '—'}
                                        </td>
                                        <td className={`${dataTable.td} t-admin-body tabular text-end`}>
                                            <Taka amount={s.totals.cod_collected} />
                                        </td>
                                        <td className={`${dataTable.td} t-admin-body tabular text-end`}>
                                            −<Taka amount={chargesOf(s)} />
                                        </td>
                                        <td className={`${dataTable.td} t-admin-body tabular text-end`}>
                                            <Taka amount={s.totals.net_received} />
                                        </td>
                                        <td className={`${dataTable.td} t-admin-body tabular`}>{shortDate(s.received_at, locale)}</td>
                                        <td className={dataTable.td}>
                                            <SettlementStatus settlement={s} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <DataTableFooter
                        summary={t('settlements.showing', {
                            count: formatCount(rows.length),
                        })}
                    >
                        {list.hasNextPage && (
                            <Button variant="outline" size="sm" onClick={() => void list.fetchNextPage()}>
                                {t('common.loadMore')}
                            </Button>
                        )}
                    </DataTableFooter>
                </section>
            )}
            {selectedId !== null && <SettlementDetail id={selectedId} />}
            {importOpen && <ImportDialog providers={providers} onClose={() => setImportOpen(false)} onImported={(id) => (setImportOpen(false), setParams({ id: String(id) }))} />}
        </div>
    );
}

/**
 * The header's deductions as the server summed them (delivery + COD fee + return). Joined as
 * text from the API's own totals — when the statement carries one deduction kind, that figure
 * is shown as-is; the browser never adds money (rule 1), so with several kinds the cell shows
 * the delivery charges and the detail lists the rest per line.
 */
function chargesOf(s: Settlement): string {
    return s.totals.delivery_charges;
}

function SettlementStatus({ settlement }: { settlement: Settlement }) {
    const { t } = useI18n();
    if (settlement.status === 'reconciled') return <StatusChip tone="success" label={t('settlements.status.reconciled')} />;
    if (settlement.status === 'disputed')
        return (
            <StatusChip
                tone="anomaly"
                label={t('settlements.status.disputed', {
                    count: settlement.unresolved_count,
                })}
            />
        );
    return <StatusChip tone="info" label={t('settlements.status.received')} />;
}

function SettlementDetail({ id }: { id: number }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const detail = useSettlement(id);
    const confirm = useConfirmSettlement(id);
    const [key] = useState(() => crypto.randomUUID());
    if (detail.isError) return <QueryError error={detail.error} onRetry={() => void detail.refetch()} />;
    const s = detail.data;
    if (!s) return null;
    const lines = s.items ?? [];

    return (
        <section className={dataTable.card} aria-labelledby="settlement-lines">
            <div className="flex flex-wrap items-start justify-between gap-3 px-4 py-4">
                <div className="flex flex-col gap-1">
                    <h2 id="settlement-lines" className="t-admin-section text-fg-primary">
                        {t('settlements.detail.title', {
                            reference: s.reference,
                        })}
                    </h2>
                    <p className="t-admin-caption text-fg-secondary">{t('settlements.detail.subtitle')}</p>
                    <p className="t-admin-caption tabular text-fg-tertiary">
                        {t('settlements.detail.totals', {
                            cod: formatMoney(s.totals.cod_collected),
                            delivery: formatMoney(s.totals.delivery_charges),
                            fee: formatMoney(s.totals.cod_fees),
                            ret: formatMoney(s.totals.return_charges),
                            net: formatMoney(s.totals.net_received),
                        })}
                    </p>
                </div>
                {s.can_reconcile && s.status !== 'reconciled' && (
                    <Button
                        disabled={s.unresolved_count > 0 || confirm.isPending}
                        title={
                            s.unresolved_count > 0
                                ? t('settlements.detail.blocked', {
                                      count: s.unresolved_count,
                                  })
                                : undefined
                        }
                        onClick={() =>
                            confirm.mutate(key, {
                                onSuccess: () =>
                                    notify(
                                        t('settlements.detail.reconciled', {
                                            reference: s.reference,
                                        }),
                                    ),
                                onError: (e) => notify(problemText(e, t('settlements.error')), 'danger'),
                            })
                        }
                    >
                        {t('settlements.detail.reconcile', {
                            reference: s.reference,
                        })}
                    </Button>
                )}
                {s.status === 'reconciled' && (
                    <StatusChip
                        tone="success"
                        label={t('settlements.detail.bookedBy', {
                            who: s.confirmed_by ?? '—',
                        })}
                    />
                )}
            </div>
            {s.unresolved_count > 0 && s.status !== 'reconciled' && (
                <p className="px-4 pb-3 t-admin-caption text-fg-primary">
                    {t('settlements.detail.blocked', {
                        count: s.unresolved_count,
                    })}
                </p>
            )}
            <div className="overflow-x-auto">
                <table className={dataTable.table}>
                    <thead className={dataTable.head}>
                        <tr>
                            <th className={dataTable.th}>{t('settlements.line.consignment')}</th>
                            <th className={dataTable.th}>{t('settlements.line.order')}</th>
                            <th className={`${dataTable.th} text-end`}>{t('settlements.line.cod')}</th>
                            <th className={`${dataTable.th} text-end`}>{t('settlements.line.delivery')}</th>
                            <th className={`${dataTable.th} text-end`}>{t('settlements.line.codFee')}</th>
                            <th className={`${dataTable.th} text-end`}>{t('settlements.line.return')}</th>
                            <th className={`${dataTable.th} text-end`}>{t('settlements.line.net')}</th>
                            <th className={`${dataTable.th} text-end`}>{t('settlements.line.expected')}</th>
                            <th className={dataTable.th}>{t('settlements.line.match')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {lines.map((line) => (
                            <LineRow key={line.id} settlement={s} line={line} />
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function LineRow({ settlement, line }: { settlement: Settlement; line: SettlementLine }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const review = useReviewLine(settlement.id);
    const [excluding, setExcluding] = useState(false);
    const [note, setNote] = useState('');
    const variance = line.variance_type !== 'none';
    const decide = (decision: 'accepted' | 'excluded', reason: string | null) =>
        review.mutate(
            { itemId: line.id, input: { decision, note: reason } },
            {
                onSuccess: () => setExcluding(false),
                onError: (e) => notify(problemText(e, t('settlements.error')), 'danger'),
            },
        );
    const deduction = (amount: string) =>
        amount === '0.00' ? (
            <Taka amount="0.00" />
        ) : (
            <>
                −<Taka amount={amount} />
            </>
        );

    return (
        <tr className={`${dataTable.row} ${variance && line.review_status === 'pending' ? 'border-l-2 border-l-feedback-warning' : ''}`}>
            <td className={`${dataTable.td} t-admin-mono`}>{line.consignment_id ?? '—'}</td>
            <td className={`${dataTable.td} t-admin-mono`}>
                {line.order_id ? (
                    <Link to={`/orders/${line.order_id}`} className="text-fg-link hover:underline">
                        {line.order_number}
                    </Link>
                ) : (
                    '—'
                )}
            </td>
            <td className={`${dataTable.td} t-admin-body tabular text-end`}>
                <Taka amount={line.cod_amount} />
            </td>
            <td className={`${dataTable.td} t-admin-body tabular text-end`}>{deduction(line.charges)}</td>
            <td className={`${dataTable.td} t-admin-body tabular text-end`}>{deduction(line.cod_fee)}</td>
            <td className={`${dataTable.td} t-admin-body tabular text-end`}>{deduction(line.return_charge)}</td>
            <td className={`${dataTable.td} t-admin-body tabular text-end`}>
                <Taka amount={line.net} />
            </td>
            <td className={`${dataTable.td} t-admin-caption tabular text-end text-fg-secondary`}>
                {line.expected_net !== null ? <Taka amount={line.expected_net} signClass="t-admin-currency-caption" /> : '—'}
            </td>
            <td className={dataTable.td}>
                <div className="flex flex-col items-start gap-1.5">
                    {variance ? (
                        <span className="inline-flex items-center gap-1 rounded-sm border border-line-default border-l-2 border-l-feedback-warning bg-subtle px-2 py-0.5 t-admin-caption-strong text-fg-primary">
                            <WarningIcon size={14} aria-hidden className="text-feedback-warning" />
                            {t(`settlements.variance.${line.variance_type}` as MessageKey, { amount: formatMoney(line.variance.replace(/^-/, '')) })}
                        </span>
                    ) : (
                        <span className="inline-flex items-center gap-1 rounded-sm border border-line-default bg-subtle px-2 py-0.5 t-admin-caption-strong text-fg-primary">
                            <CheckCircleIcon size={14} aria-hidden className="text-feedback-success" />
                            {t('settlements.variance.none')}
                        </span>
                    )}
                    {variance && line.review_status !== 'pending' && (
                        <span className="t-admin-caption text-fg-secondary">
                            {t(`settlements.review.${line.review_status}` as MessageKey)}
                            {line.review_note ? ` — ${line.review_note}` : ''}
                        </span>
                    )}
                    {variance && settlement.can_reconcile && settlement.status !== 'reconciled' && !excluding && (
                        <span className="flex gap-1.5">
                            {line.acceptable && (
                                <Button size="sm" variant="outline" disabled={review.isPending} onClick={() => decide('accepted', null)}>
                                    {t('settlements.review.accept')}
                                </Button>
                            )}
                            <Button size="sm" variant="ghost" onClick={() => setExcluding(true)}>
                                {t('settlements.review.exclude')}
                            </Button>
                        </span>
                    )}
                    {excluding && (
                        <form
                            className="flex gap-1.5"
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (note.trim()) decide('excluded', note.trim());
                            }}
                        >
                            <Input
                                size="sm"
                                aria-label={t('settlements.review.why')}
                                placeholder={t('settlements.review.why')}
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                maxLength={500}
                            />
                            <Button size="sm" type="submit" disabled={!note.trim() || review.isPending}>
                                {t('settlements.review.exclude')}
                            </Button>
                        </form>
                    )}
                </div>
            </td>
        </tr>
    );
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
