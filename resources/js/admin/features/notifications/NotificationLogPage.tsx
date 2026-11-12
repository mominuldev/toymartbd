import { useMemo, type ReactNode } from 'react';
import { Link, useSearchParams } from 'react-router';
import { ArrowClockwiseIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ApiError } from '@/lib/api';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { channels, logStatuses, useLogs, useRetry, useSummary, type Channel, type LogFilters, type LogStatus, type NotificationLog } from './api';
import { LogStatusChip, Taka, channelIcon, channelLabel, dhakaWhen, eventLabel } from './parts';

/**
 * Settings → Automations → Message log. Not drawn in Figma: StatCard 189:360 strip (this Dhaka
 * month's spend per channel + dead letters, summed by the server) over DataTable 192:532,
 * newest first, with Retry on dead letters. Filters live in the URL (`?status=&channel=&template=`).
 */
export function NotificationLogPage() {
    const { t } = useI18n();
    const [params, setParams] = useSearchParams();
    const statusParam = params.get('status') as LogStatus | null;
    const channelParam = params.get('channel') as Channel | null;
    const templateParam = params.get('template') ?? '';
    const filters: LogFilters = useMemo(
        () => ({
            status: statusParam && logStatuses.includes(statusParam) ? statusParam : '',
            channel: channelParam && channels.includes(channelParam) ? channelParam : '',
            template: /^[a-z0-9_.]{1,64}$/.test(templateParam) ? templateParam : '',
        }),
        [statusParam, channelParam, templateParam],
    );
    const logs = useLogs(filters);
    const summary = useSummary();
    const rows = useMemo(() => logs.data?.pages.flatMap((page) => page.data) ?? [], [logs.data]);

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

    const s = summary.data;
    const channelStat = (channel: Channel) => s?.channels.find((c) => c.channel === channel);

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('notif.crumb.settings') }, { label: t('notif.crumb.automations'), to: '/automations' }, { label: t('notif.log.title') }]}
                title={t('notif.log.title')}
                subtitle={t('notif.log.subtitle')}
            />

            {summary.isError ? (
                <QueryError error={summary.error} onRetry={() => void summary.refetch()} />
            ) : (
                s && (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {(['sms', 'whatsapp', 'email'] as const).map((channel) => {
                            const stat = channelStat(channel);
                            return (
                                <Stat key={channel} label={t(`notif.log.stat.${channel}` as MessageKey)} hint={channel === 'sms' ? t('notif.log.stat.sent', { count: stat?.sent ?? 0, segments: stat?.segments ?? 0 }) : t('notif.log.stat.sentOnly', { count: stat?.sent ?? 0 })}>
                                    <Taka amount={stat?.cost ?? '0.00'} signClass="t-admin-currency-num-lg" />
                                </Stat>
                            );
                        })}
                        <Stat label={t('notif.log.stat.dead')} hint={t('notif.log.stat.deadHint')} edge={s.dead_lettered > 0}>
                            {s.dead_lettered}
                        </Stat>
                    </div>
                )
            )}

            <div className="flex flex-wrap items-center gap-2">
                <label className="flex">
                    <span className="sr-only">{t('notif.log.filter.status')}</span>
                    <Select size="sm" value={filters.status} onChange={(e) => setParam('status', e.target.value)} className="w-44">
                        <option value="">{t('notif.log.filter.allStatuses')}</option>
                        {logStatuses.map((value) => (
                            <option key={value} value={value}>
                                {t(`notif.status.${value}` as MessageKey)}
                            </option>
                        ))}
                    </Select>
                </label>
                <label className="flex">
                    <span className="sr-only">{t('notif.log.filter.channel')}</span>
                    <Select size="sm" value={filters.channel} onChange={(e) => setParam('channel', e.target.value)} className="w-40">
                        <option value="">{t('notif.log.filter.allChannels')}</option>
                        {channels.map((value) => (
                            <option key={value} value={value}>
                                {t(channelLabel(value))}
                            </option>
                        ))}
                    </Select>
                </label>
                {filters.template && (
                    <span className="inline-flex h-8 items-center gap-1 rounded-sm border border-line-default bg-surface ps-3 pe-1 t-admin-caption-strong text-fg-primary">
                        {t('notif.log.filter.template', { template: t(eventLabel(filters.template)) })}
                        <button type="button" aria-label={t('notif.log.filter.clear')} onClick={() => setParam('template', '')} className="grid size-6 place-items-center rounded-xs text-fg-secondary hover:bg-subtle">
                            <XIcon size={14} aria-hidden />
                        </button>
                    </span>
                )}
            </div>

            {logs.isError ? (
                <QueryError error={logs.error} onRetry={() => void logs.refetch()} />
            ) : logs.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : rows.length === 0 ? (
                <EmptyState reason="noDataYet" title={t('notif.log.empty')} body={t('notif.log.emptyBody')} />
            ) : (
                <div className={dataTable.card}>
                    <table className={`${dataTable.table} lg:table-fixed`}>
                        <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                            <tr className="border-b border-line-strong">
                                <Th className="w-25">{t('notif.log.col.when')}</Th>
                                <Th className="w-44">{t('notif.log.col.template')}</Th>
                                <Th className="w-32">{t('notif.log.col.channel')}</Th>
                                <Th className="w-36">{t('notif.log.col.recipient')}</Th>
                                <Th className="w-34">{t('notif.log.col.order')}</Th>
                                <Th>{t('notif.log.col.status')}</Th>
                                <Th end className="w-22">
                                    {t('notif.log.col.segments')}
                                </Th>
                                <Th end className="w-20">
                                    {t('notif.log.col.cost')}
                                </Th>
                                <Th end className="w-28">
                                    <span className="sr-only">{t('notif.log.col.action')}</span>
                                </Th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <LogRow key={row.id} row={row} />
                            ))}
                        </tbody>
                    </table>
                    <DataTableFooter summary={t('notif.log.count', { count: rows.length })}>
                        {logs.hasNextPage && (
                            <Button variant="outline" size="sm" onClick={() => void logs.fetchNextPage()} disabled={logs.isFetchingNextPage}>
                                {logs.isFetchingNextPage ? t('common.loading') : t('common.loadMore')}
                            </Button>
                        )}
                    </DataTableFooter>
                </div>
            )}
        </section>
    );
}

function LogRow({ row }: { row: NotificationLog }) {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const retry = useRetry();
    const at = dhakaWhen(row.created_at ?? '', locale);
    const Glyph = channelIcon[row.channel];
    const cell = `${dataTable.td} max-lg:h-auto max-lg:p-0`;
    const num = `${cell} t-admin-num tabular lg:text-end`;

    return (
        <tr className={`flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 first:border-t-0 lg:table-row lg:p-0 lg:first:border-t ${dataTable.row}`}>
            <td className={cell}>
                <p className="t-admin-body-strong tabular whitespace-nowrap text-fg-primary">{at.day}</p>
                <p className="t-admin-caption tabular whitespace-nowrap text-fg-tertiary">{at.time}</p>
            </td>
            <td className={`${cell} t-admin-body`}>
                <p className="truncate">{t(eventLabel(row.template))}</p>
                <p className="t-admin-caption text-fg-tertiary">{row.locale === 'bn' ? 'বাংলা' : 'English'}</p>
            </td>
            <td className={`${cell} t-admin-body`}>
                <span className="inline-flex items-center gap-1.5 whitespace-nowrap">
                    <Glyph size={16} aria-hidden className="shrink-0 text-fg-secondary" />
                    {t(channelLabel(row.channel))}
                </span>
                {row.fallback_of_id !== null && <p className="t-admin-caption text-fg-tertiary">{t('notif.log.fallback')}</p>}
            </td>
            <td className={`${cell} truncate t-admin-mono-sm text-fg-secondary`}>{row.recipient ?? '—'}</td>
            <td className={`${cell} truncate t-admin-mono`}>
                {row.order_id !== null && row.order_number ? (
                    <Link to={`/orders/${row.order_id}`} className="text-fg-link hover:underline">
                        {row.order_number}
                    </Link>
                ) : (
                    '—'
                )}
            </td>
            <td className={cell}>
                <LogStatusChip status={row.status} />
                {row.error && <p className="mt-1 truncate t-admin-caption text-fg-tertiary">{row.error === 'opted_out' ? t('notif.error.opted_out') : row.error}</p>}
            </td>
            <td className={num}>{row.segments ?? '—'}</td>
            <td className={`${num} whitespace-nowrap`}>
                <Taka amount={row.cost} />
            </td>
            <td className={`${cell} lg:text-end`}>
                {row.status === 'dead_lettered' && can(me, 'settings.update') && (
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={retry.isPending}
                        onClick={() =>
                            retry.mutate(row.id, {
                                onSuccess: () => notify(t('notif.log.retried')),
                                onError: (error) => notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger'),
                            })
                        }
                    >
                        <ArrowClockwiseIcon size={14} aria-hidden />
                        {t('notif.log.retry')}
                    </Button>
                )}
            </td>
        </tr>
    );
}

/** StatCard 189:360: overline label, value, caption; `edge` = the 3px warning edge for an open problem. */
function Stat({ label, hint, edge, children }: { label: string; hint: string; edge?: boolean; children: ReactNode }) {
    return (
        <div className={`flex min-w-0 flex-col gap-1.5 overflow-hidden rounded-md bg-surface p-4 shadow-e1 ${edge ? 'border-l-3 border-feedback-warning' : 'border border-line-default'}`}>
            <p className="t-admin-overline text-fg-secondary">{label}</p>
            <p className="t-admin-num-lg tabular text-fg-primary">{children}</p>
            <p className="t-admin-caption text-fg-tertiary">{hint}</p>
        </div>
    );
}

function Th({ children, end, className = '' }: { children: ReactNode; end?: boolean; className?: string }) {
    return <th className={`${dataTable.th} ${end ? 'text-end' : ''} ${className}`}>{children}</th>;
}
