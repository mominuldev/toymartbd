import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router';
import { ArrowDownLeftIcon, ArrowUpRightIcon, CalendarBlankIcon, PiggyBankIcon, UserPlusIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { KpiCard } from '@/components/ui/KpiCard';
import { QueryError } from '@/components/ui/QueryError';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Select } from '@/components/ui/Select';
import { StatusChip } from '@/components/ui/StatusChip';
import { FinanceTabs, RowMenu, RowMenuItem, dhakaDate } from '../parts';
import { useCapitalEntries, useCapitalSummary, type CapitalEntry, type CapitalHolder, type CapitalHolderSummary, type CapitalKind, type EntryFilters } from './api';
import { CapitalEntryDialog } from './CapitalEntryDialog';
import { HolderDialog } from './HolderDialog';
import { ReverseEntryDialog } from './ReverseEntryDialog';
import { KindChips, Notice, capitalDate, initials, isMoneyIn, methodKey, roleKey, taka } from './parts';

const periods = ['all', 'month', '30', 'year'] as const;
type Period = (typeof periods)[number];

/** A period as a Dhaka date range (rule 11); the server filters `occurred_on` by it. */
function periodRange(period: Period): { from: string; to: string } {
    const today = dhakaDate();
    switch (period) {
        case 'month':
            return { from: `${today.slice(0, 8)}01`, to: today };
        case '30':
            return { from: dhakaDate(29), to: today };
        case 'year':
            return { from: `${today.slice(0, 4)}-01-01`, to: today };
        default:
            return { from: '', to: '' };
    }
}

/**
 * Finance › Owner capital (Figma B · 32 Premium 428:74277 at 1440, 428:75140 at 390). Money the
 * owner and partners put in and take out — cash only, never income, never an expense, never
 * profit (rule 10). Every figure is the server's (CapitalSummary, CapitalEntry.net_effect); the
 * page only formats. Recording, reversing and adding people need finance.manage_capital; without
 * it the page is read-only and says so.
 */
export function CapitalPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const manage = can(me, 'finance.manage_capital');
    const [params, setParams] = useSearchParams();
    const summary = useCapitalSummary();

    const holderParam = params.get('holder') ?? '';
    const kindParam = params.get('kind');
    const periodParam = params.get('period');
    const kind: '' | CapitalKind = kindParam === 'contribution' || kindParam === 'drawing' ? kindParam : '';
    const period: Period = periods.includes(periodParam as Period) ? (periodParam as Period) : 'all';
    const filters: EntryFilters = useMemo(() => ({ holder: holderParam, kind, ...periodRange(period) }), [holderParam, kind, period]);
    const entries = useCapitalEntries(filters);

    const [dialog, setDialog] = useState<CapitalKind | null>(null);
    const [holderOpen, setHolderOpen] = useState(false);
    const [addedHolder, setAddedHolder] = useState<number | null>(null);
    const [reversing, setReversing] = useState<CapitalEntry | null>(null);

    const setParam = (key: string, value: string) => {
        const next = new URLSearchParams(params);
        if (value === '') next.delete(key);
        else next.set(key, value);
        setParams(next, { replace: true });
    };

    const s = summary.data;
    const rows = entries.data?.pages.flatMap((p) => p.data) ?? [];
    const total = entries.data?.pages[0]?.meta.total ?? 0;
    const last = s?.totals.last_entry ?? null;
    const holderFigures = s?.holders ?? [];
    const filtered = filters.holder !== '' || filters.kind !== '' || filters.from !== '';

    const openEntry = (k: CapitalKind) => {
        setAddedHolder(null);
        setDialog(k);
    };
    const holderSaved = (holder: CapitalHolder) => setAddedHolder(holder.id);

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('finNav.crumb') }, { label: t('finNav.finance') }]}
                title={t('capital.title')}
                subtitle={
                    <>
                        <span className="max-sm:hidden">{t('capital.subtitle')}</span>
                        <span className="sm:hidden">{t('capital.subtitleShort')}</span>
                    </>
                }
                actions={
                    manage && (
                        <div className="flex gap-3 max-sm:hidden">
                            <Button variant="outline" onClick={() => openEntry('drawing')}>
                                <ArrowUpRightIcon size={16} aria-hidden />
                                {t('capital.ownerDrawing')}
                            </Button>
                            <Button onClick={() => openEntry('contribution')}>
                                <ArrowDownLeftIcon size={16} aria-hidden />
                                {t('capital.newInvestment')}
                            </Button>
                        </div>
                    )
                }
            />
            {/* 390: the primary first, both half width (428:75140). */}
            {manage && (
                <div className="-mt-3 grid grid-cols-2 gap-3 sm:hidden">
                    <Button onClick={() => openEntry('contribution')}>
                        <ArrowDownLeftIcon size={16} aria-hidden />
                        {t('capital.newInvestment')}
                    </Button>
                    <Button variant="outline" onClick={() => openEntry('drawing')}>
                        <ArrowUpRightIcon size={16} aria-hidden />
                        {t('capital.ownerDrawing')}
                    </Button>
                </div>
            )}
            <FinanceTabs />

            <div className="max-sm:hidden">
                <Notice title={t('capital.notice.title')}>{t('capital.notice.body')}</Notice>
            </div>
            {!manage && me && <p className="t-admin-caption text-fg-secondary">{t('capital.readOnly')}</p>}

            {summary.isError ? (
                <QueryError error={summary.error} onRetry={() => void summary.refetch()} />
            ) : (
                s && (
                    <>
                        {/* 1440: four cards in a row (428:74783). */}
                        <div className="grid gap-4 max-sm:hidden sm:grid-cols-2 xl:grid-cols-4">
                            <KpiCard tone="brand" icon={ArrowDownLeftIcon} label={t('capital.kpi.putIn')} value={<span className="t-admin-num-lg">{taka(s.totals.put_in)}</span>} hint={t('capital.kpi.putInHint')} />
                            <KpiCard tone="warning" icon={ArrowUpRightIcon} label={t('capital.kpi.takenOut')} value={<span className="t-admin-num-lg">{taka(s.totals.taken_out)}</span>} hint={t('capital.kpi.takenOutHint')} />
                            <KpiCard tone="info" icon={PiggyBankIcon} label={t('capital.kpi.stillIn')} value={<span className="t-admin-num-lg">{taka(s.totals.net)}</span>} hint={t('capital.kpi.stillInHint')} />
                            <KpiCard
                                tone="neutral"
                                icon={CalendarBlankIcon}
                                label={t('capital.kpi.last')}
                                value={<span className="t-admin-num-lg">{last ? capitalDate(last.occurred_on, locale, false) : t('capital.kpi.lastNone')}</span>}
                                hint={
                                    last
                                        ? t('capital.kpi.lastHint', {
                                              amount: taka(last.amount),
                                              direction: t(isMoneyIn(last.net_effect) ? 'capital.direction.in' : 'capital.direction.out'),
                                              name: locale === 'bn' ? (last.holder_name_bn ?? last.holder_name) : last.holder_name,
                                          })
                                        : t('capital.kpi.lastNoneHint')
                                }
                            />
                        </div>
                        {/* 390: the net first, then put in / taken out side by side (428:75140). */}
                        <div className="flex flex-col gap-3 sm:hidden">
                            <KpiCard tone="info" icon={PiggyBankIcon} label={t('capital.kpi.stillIn')} value={<span className="t-admin-num-lg">{taka(s.totals.net)}</span>} hint={t('capital.kpi.stillInHint')} />
                            <div className="grid grid-cols-2 gap-3 rounded-md border border-line-default bg-surface p-4 shadow-e1">
                                <MiniFigure label={t('capital.kpi.putIn')} value={taka(s.totals.put_in)} hint={t('capital.kpi.putInHintShort')} />
                                <MiniFigure label={t('capital.kpi.takenOut')} value={taka(s.totals.taken_out)} hint={t('capital.kpi.takenOutHintShort')} />
                            </div>
                            <Notice title={t('capital.notice.titleShort')}>{t('capital.notice.bodyShort')}</Notice>
                        </div>
                    </>
                )
            )}

            <div className="flex items-center justify-between gap-3">
                <h2 className="t-admin-section text-fg-primary">{t('capital.byPerson')}</h2>
                {manage && (
                    <Button variant="ghost" size="sm" onClick={() => setHolderOpen(true)}>
                        <UserPlusIcon size={16} aria-hidden />
                        <span className="max-sm:hidden">{t('capital.addHolder')}</span>
                        <span className="sm:hidden">{t('capital.addHolderShort')}</span>
                    </Button>
                )}
            </div>
            {s && holderFigures.length === 0 ? (
                <EmptyState
                    title={t('capital.noHolders.title')}
                    body={t('capital.noHolders.body')}
                    className="rounded-md border border-line-default bg-surface"
                    actions={
                        manage && (
                            <Button onClick={() => setHolderOpen(true)}>
                                <UserPlusIcon size={16} aria-hidden />
                                {t('capital.addHolder')}
                            </Button>
                        )
                    }
                />
            ) : (
                <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {holderFigures.map((row) => (
                        <HolderCard key={row.holder.id} row={row} />
                    ))}
                </ul>
            )}

            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <h2 className="t-admin-section text-fg-primary">{t('capital.entries')}</h2>
                <p className="t-admin-caption text-fg-tertiary max-sm:hidden">{t('capital.entries.hint')}</p>
                <label className="flex sm:hidden">
                    <span className="sr-only">{t('capital.filter.holder')}</span>
                    <HolderSelect value={holderParam} holders={holderFigures} onChange={(v) => setParam('holder', v)} className="w-35" />
                </label>
            </div>
            <div className="flex flex-wrap items-center gap-2 max-sm:hidden">
                <label className="flex">
                    <span className="sr-only">{t('capital.filter.holder')}</span>
                    <HolderSelect value={holderParam} holders={holderFigures} onChange={(v) => setParam('holder', v)} className="w-45" />
                </label>
                <SegmentedControl
                    label={t('capital.filter.kind')}
                    className="h-9 p-1"
                    options={[
                        { value: '', label: t('capital.filter.all') },
                        { value: 'contribution', label: t('capital.filter.in') },
                        { value: 'drawing', label: t('capital.filter.out') },
                    ]}
                    value={kind}
                    onChange={(v) => setParam('kind', v)}
                />
                <label className="flex">
                    <span className="sr-only">{t('capital.filter.period')}</span>
                    <Select size="sm" value={period} onChange={(e) => setParam('period', e.target.value === 'all' ? '' : e.target.value)} className="w-40">
                        {periods.map((p) => (
                            <option key={p} value={p}>
                                {t(`capital.period.${p}` as MessageKey)}
                            </option>
                        ))}
                    </Select>
                </label>
            </div>

            {entries.isError ? (
                <QueryError error={entries.error} onRetry={() => void entries.refetch()} />
            ) : (
                <div className={dataTable.card}>
                    {/* ≥640: the table (428:74997). */}
                    <div className="overflow-x-auto max-sm:hidden">
                        <table className={`${dataTable.table} min-w-[71.5rem] table-fixed`}>
                            <caption className="sr-only">{t('capital.entries')}</caption>
                            <thead className={dataTable.head}>
                                <tr>
                                    <th scope="col" className={`${dataTable.th} w-[9%]`}>{t('capital.col.date')}</th>
                                    <th scope="col" className={`${dataTable.th} w-[15%]`}>{t('capital.col.person')}</th>
                                    <th scope="col" className={`${dataTable.th} w-[17.5%]`}>{t('capital.col.kind')}</th>
                                    <th scope="col" className={`${dataTable.th} w-[14%]`}>{t('capital.col.how')}</th>
                                    <th scope="col" className={dataTable.th}>{t('capital.col.note')}</th>
                                    <th scope="col" className={`${dataTable.th} w-[11.5%]`}>{t('capital.col.recordedBy')}</th>
                                    <th scope="col" className={`${dataTable.th} w-[11%] text-end`}>{t('capital.col.amount')}</th>
                                    <th scope="col" className={`${dataTable.th} w-13`}>
                                        <span className="sr-only">{t('capital.col.actions')}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((entry) => (
                                    <EntryRow key={entry.id} entry={entry} manage={manage} onReverse={() => setReversing(entry)} />
                                ))}
                                {entries.isSuccess && rows.length === 0 && (
                                    <tr className="border-t border-line-default">
                                        <td colSpan={8} className="px-4 py-8 text-center t-admin-body text-fg-secondary">
                                            {t(filtered ? 'capital.noEntries' : 'capital.noEntriesYet')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                    {/* 390: one card per entry. */}
                    <ul className="sm:hidden">
                        {rows.map((entry) => (
                            <EntryItem key={entry.id} entry={entry} manage={manage} onReverse={() => setReversing(entry)} />
                        ))}
                        {entries.isSuccess && rows.length === 0 && <li className="px-4 py-8 text-center t-admin-body text-fg-secondary">{t(filtered ? 'capital.noEntries' : 'capital.noEntriesYet')}</li>}
                    </ul>
                    {rows.length > 0 && (
                        <DataTableFooter summary={t('capital.showing', { shown: rows.length, total })}>
                            {entries.hasNextPage && (
                                <Button variant="ghost" size="sm" disabled={entries.isFetchingNextPage} onClick={() => void entries.fetchNextPage()}>
                                    {t('capital.loadMore')}
                                </Button>
                            )}
                        </DataTableFooter>
                    )}
                </div>
            )}

            {manage && (
                <>
                    <CapitalEntryDialog kind="contribution" open={dialog === 'contribution'} onClose={() => setDialog(null)} figures={holderFigures} holderId={addedHolder} onAddHolder={() => setHolderOpen(true)} />
                    <CapitalEntryDialog kind="drawing" open={dialog === 'drawing'} onClose={() => setDialog(null)} figures={holderFigures} holderId={addedHolder} onAddHolder={() => setHolderOpen(true)} />
                    <ReverseEntryDialog entry={reversing} onClose={() => setReversing(null)} />
                    <HolderDialog open={holderOpen} onClose={() => setHolderOpen(false)} onSaved={holderSaved} />
                </>
            )}
        </section>
    );
}

function MiniFigure({ label, value, hint }: { label: string; value: string; hint: string }) {
    return (
        <div className="flex min-w-0 flex-col gap-1">
            <p className="t-admin-overline text-fg-secondary">{label}</p>
            <p className="t-admin-num-md text-fg-primary tabular">{value}</p>
            <p className="t-admin-caption text-fg-secondary">{hint}</p>
        </div>
    );
}

function HolderSelect({ value, holders, onChange, className }: { value: string; holders: CapitalHolderSummary[]; onChange: (value: string) => void; className: string }) {
    const { t } = useI18n();
    return (
        <Select size="sm" value={value} onChange={(e) => onChange(e.target.value)} className={className}>
            <option value="">{t('capital.filter.everyone')}</option>
            {holders.map((h) => (
                <option key={h.holder.id} value={h.holder.id}>
                    {h.holder.name}
                </option>
            ))}
        </Select>
    );
}

/** Figma "Holder · …" card (428:74841): avatar, names, role chip, three figures, last entry. */
function HolderCard({ row }: { row: CapitalHolderSummary }) {
    const { t, locale } = useI18n();
    const h = row.holder;
    const role = t(roleKey(h.role));
    return (
        <li className="flex min-w-0 flex-col gap-3.5 rounded-md border border-line-default bg-surface p-4 shadow-e1">
            <div className="flex items-center gap-3">
                <span aria-hidden className="grid size-10 shrink-0 place-items-center rounded-full bg-brand-subtle t-admin-caption-strong text-fg-brand">
                    {initials(h.name)}
                </span>
                <div className="flex min-w-0 flex-1 flex-col">
                    <p className="truncate t-admin-body-strong text-fg-primary">{h.name}</p>
                    {h.name_bn && (
                        <p lang="bn" className="truncate t-admin-caption text-fg-secondary">
                            {h.name_bn}
                        </p>
                    )}
                </div>
                <span className="flex shrink-0 flex-col items-end gap-1">
                    <StatusChip tone="neutral" showIcon={false} label={h.has_staff_login ? t('capital.role.withLogin', { role }) : role} />
                    {!h.is_active && <StatusChip tone="neutral" label={t('capital.inactive')} />}
                </span>
            </div>
            <dl className="grid grid-cols-3 gap-3">
                <Figure label={t('capital.holder.putIn')} value={taka(row.put_in)} />
                <Figure label={t('capital.holder.takenOut')} value={taka(row.taken_out)} />
                <Figure label={t('capital.holder.stillIn')} value={taka(row.net)} brand />
            </dl>
            <p className="flex flex-wrap gap-1.5 border-t border-line-default pt-2.5 t-admin-caption">
                <span className="text-fg-tertiary">{t('capital.holder.last')}</span>
                <span className="text-fg-secondary tabular">
                    {row.last_entry
                        ? t('capital.holder.lastValue', {
                              date: capitalDate(row.last_entry.occurred_on, locale, false),
                              amount: taka(row.last_entry.amount),
                              direction: t(isMoneyIn(row.last_entry.net_effect) ? 'capital.direction.in' : 'capital.direction.out'),
                          })
                        : t('capital.holder.noEntries')}
                </span>
            </p>
        </li>
    );
}

function Figure({ label, value, brand = false }: { label: string; value: string; brand?: boolean }) {
    return (
        <div className="flex min-w-0 flex-col gap-0.5">
            <dt className="t-admin-caption text-fg-tertiary">{label}</dt>
            <dd className={`truncate t-admin-num tabular ${brand ? 'text-fg-brand' : 'text-fg-primary'}`}>{value}</dd>
        </div>
    );
}

/** The signed amount: money in success-green with "+", money out plain with "−" (shape, not colour alone). */
function Amount({ entry, muted }: { entry: CapitalEntry; muted: boolean }) {
    const tone = muted ? 'text-fg-tertiary' : isMoneyIn(entry.net_effect) ? 'text-feedback-success' : 'text-fg-primary';
    return <span className={`t-admin-num tabular whitespace-nowrap ${tone}`}>{taka(entry.net_effect, true)}</span>;
}

function ReverseMenu({ entry, onReverse }: { entry: CapitalEntry; onReverse: () => void }) {
    const { t } = useI18n();
    return (
        <RowMenu label={t('capital.rowMenu', { id: entry.id })}>
            <RowMenuItem onClick={onReverse}>{t('capital.reverse')}</RowMenuItem>
        </RowMenu>
    );
}

/** One table row (428:75016). A reversed original is muted; a reversal names what it cancels. */
function EntryRow({ entry, manage, onReverse }: { entry: CapitalEntry; manage: boolean; onReverse: () => void }) {
    const { t, locale } = useI18n();
    const muted = entry.reversed_by_id !== null;
    const text = muted ? 'text-fg-tertiary' : 'text-fg-primary';
    return (
        <tr className={dataTable.row}>
            <td className={`${dataTable.td} t-admin-body whitespace-nowrap tabular ${text}`}>{capitalDate(entry.occurred_on, locale)}</td>
            <td className={dataTable.td}>
                <p className={`t-admin-body-strong ${text}`}>{entry.holder?.name}</p>
                {entry.holder?.name_bn && (
                    <p lang="bn" className={`t-admin-caption ${muted ? 'text-fg-tertiary' : 'text-fg-secondary'}`}>
                        {entry.holder.name_bn}
                    </p>
                )}
            </td>
            <td className={dataTable.td}>
                <span className={`flex flex-col items-start gap-0.5 ${muted ? 'opacity-70' : ''}`}>
                    <KindChips entry={entry} />
                    {entry.is_reversal && entry.reversal_of_id !== null && <span className="t-admin-caption text-fg-secondary">{t('capital.cancels', { id: entry.reversal_of_id })}</span>}
                </span>
            </td>
            <td className={dataTable.td}>
                <p className={`t-admin-body ${text}`}>{t(methodKey(entry.method))}</p>
                {entry.reference && <p className="t-admin-mono-sm text-fg-secondary">{entry.reference}</p>}
            </td>
            <td className={`${dataTable.td} t-admin-body ${muted ? 'text-fg-tertiary' : 'text-fg-primary'}`}>
                <span className="block truncate" title={entry.note ?? undefined}>
                    {entry.note}
                </span>
            </td>
            <td className={`${dataTable.td} t-admin-body ${muted ? 'text-fg-tertiary' : 'text-fg-primary'}`}>
                <span className="line-clamp-2">{entry.recorded_by?.name ?? '—'}</span>
            </td>
            <td className={`${dataTable.td} text-end`}>
                <Amount entry={entry} muted={muted} />
            </td>
            <td className={`${dataTable.td} text-end`}>{manage && entry.can.reverse && <ReverseMenu entry={entry} onReverse={onReverse} />}</td>
        </tr>
    );
}

/** One entry card at 390 (428:75140 "Every entry"). */
function EntryItem({ entry, manage, onReverse }: { entry: CapitalEntry; manage: boolean; onReverse: () => void }) {
    const { t, locale } = useI18n();
    const muted = entry.reversed_by_id !== null;
    const meta = [
        capitalDate(entry.occurred_on, locale, false),
        t(methodKey(entry.method)),
        entry.reference,
        entry.is_reversal && entry.reversal_of_id !== null ? t('capital.cancelsShort', { id: entry.reversal_of_id }) : null,
        muted ? t('capital.reversedShort') : null,
    ].filter(Boolean);
    return (
        <li className="flex items-start gap-3 border-t border-line-default px-4 py-3 first:border-t-0">
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <p className="flex flex-wrap items-center gap-2">
                    <span className={`t-admin-body-strong ${muted ? 'text-fg-tertiary' : 'text-fg-primary'}`}>{entry.holder?.name}</span>
                    <KindChips entry={entry} short />
                </p>
                <p className={`t-admin-caption tabular ${muted ? 'text-fg-tertiary' : 'text-fg-secondary'}`}>{meta.join(' · ')}</p>
                {entry.note && <p className={`t-admin-caption ${muted ? 'text-fg-tertiary' : 'text-fg-secondary'}`}>{entry.note}</p>}
            </div>
            <div className="flex shrink-0 items-center gap-1 self-center">
                <Amount entry={entry} muted={muted} />
                {manage && entry.can.reverse && <ReverseMenu entry={entry} onReverse={onReverse} />}
            </div>
        </li>
    );
}
