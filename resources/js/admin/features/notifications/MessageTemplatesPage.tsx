import { useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { CheckIcon, MinusIcon, PlusIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ApiError } from '@/lib/api';
import { can, useMe } from '@/features/auth/api';
import { buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { Select } from '@/components/ui/Select';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import {
    channels,
    groupOf,
    libraryGroups,
    useLibrary,
    useSetTemplatesActive,
    type Channel,
    type LibraryFilters,
    type LibraryGroup,
    type LibraryStatus,
    type NotificationTemplate,
} from './api';
import { Taka, Toggle, channelIcon, channelLabel, dhakaWhen, templateTitle } from './parts';

type Row = { key: string; group: LibraryGroup; templates: NotificationTemplate[] };

const statuses: LibraryStatus[] = ['active', 'off', 'archived'];

function rowsOf(templates: NotificationTemplate[]): Row[] {
    const map = new Map<string, Row>();
    for (const tpl of templates) {
        const row = map.get(tpl.key) ?? { key: tpl.key, group: groupOf(tpl), templates: [] };
        row.templates.push(tpl);
        map.set(tpl.key, row);
    }
    for (const row of map.values()) row.templates.sort((a, b) => channels.indexOf(a.channel) - channels.indexOf(b.channel));
    return [...map.values()];
}

/**
 * Settings → Automations → Message templates (P7, owner request 2026-11-13). Figma has the
 * "Message templates" button on B · 36 Automations (241:49914) but no frame for the screen
 * itself, so it follows the Automations page's ContentHeader and the DataTable pattern
 * (Message log). Every template the server's catalog holds is listed — keys are never
 * hardcoded — grouped by what it is for. Segment counts and per-send costs are the server's.
 */
export function MessageTemplatesPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const [params, setParams] = useSearchParams();
    const filters: LibraryFilters = {
        channel: (params.get('channel') as Channel | null) ?? '',
        group: (params.get('group') as LibraryGroup | null) ?? '',
        status: (params.get('status') as LibraryStatus | null) ?? '',
    };
    const [search, setSearch] = useState(params.get('q') ?? '');
    const library = useLibrary(filters);

    const setParam = (name: string, value: string) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                if (value) next.set(name, value);
                else next.delete(name);
                return next;
            },
            { replace: true },
        );

    // Search runs over what the server returned: system template names live in this
    // catalogue (EN/BN), so matching "Order shipped" or "অর্ডার" needs the translated name.
    const rows = useMemo(() => {
        const needle = search.trim().toLocaleLowerCase();
        const all = rowsOf(library.data ?? []);
        if (!needle) return all;
        return all.filter((row) =>
            row.templates.some((tpl) =>
                [templateTitle(tpl, t, locale), tpl.key, tpl.name_en ?? '', tpl.name_bn ?? '', tpl.body_en, tpl.body_bn].some((text) => text.toLocaleLowerCase().includes(needle)),
            ),
        );
    }, [library.data, search, t, locale]);

    const grouped = libraryGroups.map((group) => ({ group, rows: rows.filter((row) => row.group === group) })).filter((g) => g.rows.length > 0);

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('notif.crumb.settings') }, { label: t('notif.crumb.automations'), to: '/automations' }, { label: t('notif.lib.crumb') }]}
                title={t('notif.lib.title')}
                subtitle={t('notif.lib.subtitle')}
                actions={
                    can(me, 'settings.update') ? (
                        <Link to="/automations/templates/new" className={`${buttonClass('primary', 'md')} h-9`}>
                            <PlusIcon size={16} aria-hidden />
                            {t('notif.lib.new')}
                        </Link>
                    ) : undefined
                }
            />

            <div className="flex flex-wrap items-center gap-2">
                <SearchField
                    label={t('notif.lib.search')}
                    placeholder={t('notif.lib.search')}
                    value={search}
                    onChange={(e) => {
                        setSearch(e.target.value);
                        setParam('q', e.target.value);
                    }}
                    className="w-full sm:w-72"
                />
                <label className="flex">
                    <span className="sr-only">{t('notif.lib.filter.channel')}</span>
                    <Select size="sm" value={filters.channel} onChange={(e) => setParam('channel', e.target.value)} className="w-40">
                        <option value="">{t('notif.log.filter.allChannels')}</option>
                        {channels.map((value) => (
                            <option key={value} value={value}>
                                {t(channelLabel(value))}
                            </option>
                        ))}
                    </Select>
                </label>
                <label className="flex">
                    <span className="sr-only">{t('notif.lib.filter.group')}</span>
                    <Select size="sm" value={filters.group} onChange={(e) => setParam('group', e.target.value)} className="w-44">
                        <option value="">{t('notif.lib.filter.allGroups')}</option>
                        {libraryGroups.map((value) => (
                            <option key={value} value={value}>
                                {t(`notif.lib.group.${value}` as MessageKey)}
                            </option>
                        ))}
                    </Select>
                </label>
                <label className="flex">
                    <span className="sr-only">{t('notif.lib.filter.status')}</span>
                    <Select size="sm" value={filters.status} onChange={(e) => setParam('status', e.target.value)} className="w-40">
                        <option value="">{t('notif.lib.filter.allStatuses')}</option>
                        {statuses.map((value) => (
                            <option key={value} value={value}>
                                {t(`notif.lib.status.${value}` as MessageKey)}
                            </option>
                        ))}
                    </Select>
                </label>
            </div>

            {library.isError ? (
                <QueryError error={library.error} onRetry={() => void library.refetch()} />
            ) : library.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : grouped.length === 0 ? (
                <EmptyState reason="noDataYet" title={t('notif.lib.empty')} body={t('notif.lib.emptyBody')} />
            ) : (
                grouped.map(({ group, rows: groupRows }) => (
                    <section key={group} aria-labelledby={`tpl-group-${group}`} className="flex flex-col gap-3">
                        <header className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                            <h2 id={`tpl-group-${group}`} className="t-admin-card-title text-fg-primary">
                                {t(`notif.lib.group.${group}` as MessageKey)}
                            </h2>
                            <span className="t-admin-caption tabular text-fg-tertiary">{t('notif.lib.count', { count: groupRows.length })}</span>
                            <p className="w-full t-admin-caption text-fg-secondary">{t(`notif.lib.groupHint.${group}` as MessageKey)}</p>
                        </header>
                        <div className={dataTable.card}>
                            <table className={`${dataTable.table} lg:table-fixed`}>
                                <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                                    <tr className="border-b border-line-strong">
                                        <th className={dataTable.th}>{t('notif.lib.col.template')}</th>
                                        <th className={`${dataTable.th} w-56`}>{t('notif.lib.col.channels')}</th>
                                        <th className={`${dataTable.th} w-28`}>{t('notif.lib.col.languages')}</th>
                                        <th className={`${dataTable.th} w-44`}>{t('notif.lib.col.sms')}</th>
                                        <th className={`${dataTable.th} w-44`}>{t('notif.lib.col.edited')}</th>
                                        <th className={`${dataTable.th} w-20 text-end`}>{t('notif.lib.col.active')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {groupRows.map((row) => (
                                        <LibraryRow key={row.key} row={row} />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                ))
            )}
        </section>
    );
}

/** One template (all its channels): name + key, channel chips into the editor, EN/BN status, SMS segments + cost, last edit, switch. */
function LibraryRow({ row }: { row: Row }) {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const navigate = useNavigate();
    const first = row.templates[0]!;
    const sms = row.templates.find((tpl) => tpl.channel === 'sms');
    const archived = first.archived_at !== null;
    const security = first.category === 'security';
    const latest = row.templates.reduce((a, b) => ((b.updated_at ?? '') > (a.updated_at ?? '') ? b : a));
    const edited = latest.updated_at ? dhakaWhen(latest.updated_at, locale) : null;
    const filled = (side: 'en' | 'bn') => row.templates.every((tpl) => (side === 'en' ? tpl.body_en : tpl.body_bn).trim() !== '');
    const cell = `${dataTable.td} max-lg:h-auto max-lg:p-0`;

    return (
        <tr
            className={`flex cursor-pointer flex-wrap items-center gap-x-4 gap-y-1.5 px-4 py-3 first:border-t-0 lg:table-row lg:p-0 lg:first:border-t ${dataTable.row}`}
            onClick={() => navigate(`/automations/templates/${first.id}`)}
        >
            <td className={`${cell} w-full lg:w-auto`}>
                <Link to={`/automations/templates/${first.id}`} onClick={(e) => e.stopPropagation()} className="block truncate t-admin-body-strong text-fg-primary hover:underline">
                    {templateTitle(first, t, locale)}
                </Link>
                <p className="flex items-center gap-1.5 truncate t-admin-mono-sm text-fg-tertiary">
                    {row.key}
                    {first.is_custom && <span className="font-sans t-admin-caption text-fg-secondary">· {t(`notif.lib.kind.${first.kind}` as MessageKey)}</span>}
                </p>
            </td>
            <td className={cell}>
                <ul aria-label={t('notif.lib.col.channels')} className="flex flex-wrap gap-1.5">
                    {row.templates.map((tpl) => {
                        const Glyph = channelIcon[tpl.channel];
                        return (
                            <li key={tpl.id}>
                                <Link
                                    to={`/automations/templates/${tpl.id}`}
                                    onClick={(e) => e.stopPropagation()}
                                    aria-label={`${t(channelLabel(tpl.channel))} · ${tpl.is_active ? t('notif.lib.status.active') : t('notif.lib.status.off')}`}
                                    className={`inline-flex h-6 items-center gap-1 rounded-full border px-2 t-admin-caption-strong whitespace-nowrap hover:bg-subtle ${
                                        tpl.is_active ? 'border-line-default bg-surface text-fg-primary' : 'border-dashed border-line-strong bg-subtle text-fg-tertiary'
                                    }`}
                                >
                                    <Glyph size={12} aria-hidden />
                                    {t(channelLabel(tpl.channel))}
                                </Link>
                            </li>
                        );
                    })}
                </ul>
                {row.templates.some((tpl) => tpl.whatsapp_status && tpl.whatsapp_status !== 'approved') && (
                    <p className="mt-1 t-admin-caption text-fg-tertiary">{t('notif.lib.waPending')}</p>
                )}
            </td>
            <td className={cell}>
                <span className="inline-flex items-center gap-2 t-admin-caption-strong">
                    <LangMark label="EN" ok={filled('en')} />
                    <LangMark label="BN" ok={filled('bn')} />
                </span>
            </td>
            <td className={`${cell} t-admin-caption tabular text-fg-secondary`}>
                {sms ? (
                    <>
                        <p>{t('notif.lib.segments', { en: sms.segments_en ?? '—', bn: sms.segments_bn ?? '—' })}</p>
                        {sms.cost_en !== null && sms.cost_bn !== null && (
                            <p className="text-fg-tertiary">
                                <Taka amount={sms.cost_en} signClass="t-admin-currency-caption" /> / <Taka amount={sms.cost_bn} signClass="t-admin-currency-caption" /> {t('notif.lib.perSend')}
                            </p>
                        )}
                    </>
                ) : (
                    <span className="text-fg-tertiary">—</span>
                )}
            </td>
            <td className={`${cell} t-admin-caption text-fg-secondary`}>
                {edited ? (
                    <>
                        <p className="truncate">{latest.updated_by_name ?? t('notif.lib.bySystem')}</p>
                        <p className="tabular text-fg-tertiary">
                            {edited.day} · {edited.time}
                        </p>
                    </>
                ) : (
                    '—'
                )}
            </td>
            <td className={`${cell} lg:text-end`} onClick={(e) => e.stopPropagation()}>
                {archived ? (
                    <StatusChip tone="neutral" label={t('notif.lib.status.archived')} />
                ) : (
                    <RowToggle templates={row.templates} title={templateTitle(first, t, locale)} disabled={!can(me, 'settings.update') || security} />
                )}
            </td>
        </tr>
    );
}

function LangMark({ label, ok }: { label: string; ok: boolean }) {
    const { t } = useI18n();
    const Glyph = ok ? CheckIcon : MinusIcon;
    return (
        <span className={`inline-flex items-center gap-0.5 ${ok ? 'text-fg-primary' : 'text-fg-tertiary'}`} aria-label={`${label} · ${ok ? t('notif.lib.filled') : t('notif.lib.missing')}`}>
            <Glyph size={12} aria-hidden className={ok ? 'text-feedback-success' : ''} />
            {label}
        </span>
    );
}

/** The row switch turns every channel of the template on or off. */
function RowToggle({ templates, title, disabled }: { templates: NotificationTemplate[]; title: string; disabled: boolean }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useSetTemplatesActive();
    const active = templates.some((tpl) => tpl.is_active);

    return (
        <Toggle
            checked={active}
            disabled={disabled || save.isPending}
            label={t('notif.card.toggle', { name: title })}
            onChange={(next) =>
                save.mutate(
                    { templates, active: next },
                    {
                        onSuccess: () => notify(t('notif.editor.saved')),
                        onError: (error) => notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger'),
                    },
                )
            }
        />
    );
}
