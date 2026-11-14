import { useId, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { DownloadSimpleIcon, GearSixIcon, ShieldIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { api } from '@/lib/api';
import { Avatar } from '@/components/shell/Avatar';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button, buttonClass } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { auditAreas, auditExportUrl, dhakaToday, presetRange, rangePresets, useAuditLog, type AuditEntry, type AuditFilters, type RangePreset } from './api';

type StaffMember = Schema<'StaffResource'>;

/** Everyone who could appear as the person filter: every page of `GET /staff` (needs staff.view). */
function usePeople(enabled: boolean) {
    return useQuery({
        queryKey: ['audit', 'people'],
        queryFn: async () => {
            const people: StaffMember[] = [];
            let cursor: string | null | undefined = null;
            do {
                const page: { data: StaffMember[]; meta: { next_cursor?: string | null } } = await api(`/staff${cursor ? `?cursor=${encodeURIComponent(cursor)}` : ''}`);
                people.push(...page.data);
                cursor = page.meta.next_cursor;
            } while (cursor);
            return people;
        },
        enabled,
        staleTime: 5 * 60_000,
    });
}

function format(locale: string, options: Intl.DateTimeFormatOptions, value: Date): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { timeZone: 'Asia/Dhaka', numberingSystem: 'latn', ...options }).format(value);
}

/** "02–08 Nov" (one month) or "28 Oct – 03 Nov" — Western digits in both locales (rule 36). */
function rangeText(from: string, to: string, locale: string): string {
    const a = new Date(`${from}T12:00:00+06:00`);
    const b = new Date(`${to}T12:00:00+06:00`);
    if (from === to) return format(locale, { day: '2-digit', month: 'short' }, b);
    if (from.slice(0, 7) === to.slice(0, 7)) return `${format(locale, { day: '2-digit' }, a)}–${format(locale, { day: '2-digit', month: 'short' }, b)}`;
    return `${format(locale, { day: '2-digit', month: 'short' }, a)} – ${format(locale, { day: '2-digit', month: 'short' }, b)}`;
}

/**
 * Settings → Staff & roles → Activity log (Figma B · 39 241:51276): the immutable audit trail,
 * newest first, grouped by Dhaka day. Every value shown is the server's — area, sentence kind,
 * changes, the masked IP and the short device string. The frame's "Only sensitive changes" and
 * "Source" filters and its webhook / PII-reveal rows are not built: only sensitive changes are
 * audited today, and webhook events and PII reveals are not logged to this trail yet.
 */
export function ActivityLogPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const allowed = can(me, 'audit.view');
    const today = useMemo(() => dhakaToday(), []);
    const [preset, setPreset] = useState<RangePreset>('7d');
    const [custom, setCustom] = useState(() => presetRange('7d', today));
    const [userId, setUserId] = useState('');
    const [area, setArea] = useState('');
    const id = useId();

    const range = preset === 'custom' ? custom : presetRange(preset, today);
    const filters: AuditFilters = { from: range.from, to: range.to, userId, area };
    const log = useAuditLog(filters, allowed);
    const people = usePeople(allowed && can(me, 'staff.view'));

    if (me && !allowed) {
        return <EmptyState reason="noPermission" title={t('error.forbidden')} />;
    }

    const rows = log.data?.pages.flatMap((p) => p.data) ?? [];

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('audit.crumb.settings'), to: '/settings' }, { label: t('audit.crumb.staff'), to: '/staff' }, { label: t('audit.title') }]}
                title={t('audit.title')}
                subtitle={t('audit.subtitle')}
                actions={
                    <>
                        <label htmlFor={`${id}-range`} className="sr-only">
                            {t('audit.range.label')}
                        </label>
                        <Select
                            id={`${id}-range`}
                            value={preset}
                            className="w-full sm:w-64"
                            onChange={(e) => {
                                const next = e.target.value as RangePreset;
                                if (next === 'custom') setCustom(range);
                                setPreset(next);
                            }}
                        >
                            {rangePresets.map((p) => (
                                <option key={p} value={p}>
                                    {p === 'custom' ? t('audit.range.custom') : t(`audit.range.${p}` as MessageKey, { range: rangeText(presetRange(p, today).from, today, locale) })}
                                </option>
                            ))}
                        </Select>
                        {can(me, 'reports.export') && (
                            <a href={auditExportUrl(filters)} download className={buttonClass('outline')} title={t('audit.exportHint')}>
                                <DownloadSimpleIcon size={16} aria-hidden />
                                {t('audit.export')}
                            </a>
                        )}
                    </>
                }
            />

            <div role="group" aria-label={t('audit.filters')} className="flex flex-wrap items-center gap-2">
                {preset === 'custom' && (
                    <>
                        <label htmlFor={`${id}-from`} className="sr-only">
                            {t('audit.range.from')}
                        </label>
                        <div className="w-40 shrink-0">
                            <Input id={`${id}-from`} type="date" max={custom.to} value={custom.from} onChange={(e) => e.target.value && setCustom({ ...custom, from: e.target.value })} className="tabular" />
                        </div>
                        <label htmlFor={`${id}-to`} className="sr-only">
                            {t('audit.range.to')}
                        </label>
                        <div className="w-40 shrink-0">
                            <Input id={`${id}-to`} type="date" min={custom.from} max={today} value={custom.to} onChange={(e) => e.target.value && setCustom({ ...custom, to: e.target.value })} className="tabular" />
                        </div>
                    </>
                )}
                {can(me, 'staff.view') && (
                    <>
                        <label htmlFor={`${id}-person`} className="sr-only">
                            {t('audit.person.label')}
                        </label>
                        <Select id={`${id}-person`} value={userId} onChange={(e) => setUserId(e.target.value)} className="w-full sm:w-47.5">
                            <option value="">{t('audit.person.any')}</option>
                            {(people.data ?? []).map((p) => (
                                <option key={p.id} value={String(p.id)}>
                                    {t('audit.person.option', { name: p.name })}
                                </option>
                            ))}
                        </Select>
                    </>
                )}
                <label htmlFor={`${id}-area`} className="sr-only">
                    {t('audit.area.label')}
                </label>
                <Select id={`${id}-area`} value={area} onChange={(e) => setArea(e.target.value)} className="w-full sm:w-47.5">
                    <option value="">{t('audit.area.all')}</option>
                    {auditAreas.map((a) => (
                        <option key={a} value={a}>
                            {t('audit.area.option', { area: t(`audit.area.${a}` as MessageKey) })}
                        </option>
                    ))}
                </Select>
            </div>

            {log.isError ? (
                <QueryError error={log.error} onRetry={() => void log.refetch()} />
            ) : log.isPending ? (
                <p className="t-admin-body text-fg-secondary" aria-busy="true">
                    {t('audit.loading')}
                </p>
            ) : rows.length === 0 ? (
                <EmptyState reason="allClear" title={t('audit.empty')} body={t('audit.emptyBody')} />
            ) : (
                <>
                    <Feed rows={rows} today={today} />
                    {log.hasNextPage && (
                        <div className="flex justify-center">
                            <Button variant="outline" onClick={() => void log.fetchNextPage()} disabled={log.isFetchingNextPage}>
                                {t('audit.loadMore')}
                            </Button>
                        </div>
                    )}
                </>
            )}
        </section>
    );
}

/** The feed card: one header per Dhaka day ("Today · 08 Nov"), then that day's rows. */
function Feed({ rows, today }: { rows: AuditEntry[]; today: string }) {
    const { t, locale } = useI18n();
    const yesterday = useMemo(() => {
        const d = new Date(`${today}T00:00:00Z`);
        d.setUTCDate(d.getUTCDate() - 1);
        return d.toISOString().slice(0, 10);
    }, [today]);

    const days: { day: string; rows: AuditEntry[] }[] = [];
    for (const row of rows) {
        const last = days[days.length - 1];
        if (last && last.day === row.day) last.rows.push(row);
        else days.push({ day: row.day, rows: [row] });
    }

    const heading = (day: string): string => {
        const date = new Date(`${day}T12:00:00+06:00`);
        const short = format(locale, { day: '2-digit', month: 'short' }, date);
        if (day === today) return t('audit.today', { date: short });
        if (day === yesterday) return t('audit.yesterday', { date: short });
        return `${format(locale, { weekday: 'short' }, date)} · ${short}`;
    };

    return (
        <div className="overflow-hidden rounded-md border border-line-default bg-surface shadow-e1">
            {days.map(({ day, rows: dayRows }) => (
                <section key={day} aria-labelledby={`audit-day-${day}`}>
                    <h2 id={`audit-day-${day}`} className="border-b border-line-default bg-subtle px-4 py-2 t-admin-overline text-fg-secondary">
                        {heading(day)}
                    </h2>
                    <ol>
                        {dayRows.map((row) => (
                            <Row key={row.id} row={row} />
                        ))}
                    </ol>
                </section>
            ))}
        </div>
    );
}

function Row({ row }: { row: AuditEntry }) {
    const { t, locale } = useI18n();
    const subject = subjectLabel(row, t);
    const time = format(locale, { hour: 'numeric', minute: '2-digit', hour12: true }, new Date(row.created_at));
    const caption = [time, t(`audit.area.${row.area}` as MessageKey), row.ip, row.device].filter(Boolean).join(' · ');

    return (
        <li className="flex gap-3 border-b border-line-default px-4 py-4 last:border-b-0">
            {row.actor ? (
                <Avatar name={row.actor.name} />
            ) : (
                <span aria-hidden className="grid size-8 shrink-0 place-items-center rounded-full bg-subtle text-fg-secondary">
                    <GearSixIcon size={16} />
                </span>
            )}
            <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                <p className="t-admin-body text-fg-primary">
                    <span className="t-admin-body-strong">{row.actor?.name ?? t('audit.system')}</span> {t(`audit.kind.${row.kind}` as MessageKey, { subject })}
                </p>
                {row.changes.length > 0 && (
                    <ul aria-label={t('audit.changes')} className="flex flex-wrap gap-1.5">
                        {row.changes.map((change) => (
                            <li key={change.field} className="rounded-xs bg-subtle px-2 py-1 t-admin-mono-sm text-fg-primary">
                                {change.field}: {change.before !== null ? `${change.before} → ` : ''}
                                {change.after ?? '—'}
                            </li>
                        ))}
                    </ul>
                )}
                <p className="t-admin-caption text-fg-secondary tabular">{caption}</p>
            </div>
            <span className="flex shrink-0 items-start gap-1 t-admin-caption-strong text-fg-primary" title={t('audit.auditedHint')}>
                <ShieldIcon size={14} aria-hidden className="mt-0.5 text-feedback-warning" />
                {t('audit.audited')}
            </span>
        </li>
    );
}

function subjectLabel(row: AuditEntry, t: ReturnType<typeof useI18n>['t']): string {
    const subject = row.subject;
    if (!subject) return '';
    if (subject.label === null) return subject.id === null ? '' : t('audit.subject.deleted', { id: String(subject.id) });
    if (subject.type === 'role') {
        const key = `role.${subject.label}`;
        return key in roleKeys ? t(key as MessageKey) : subject.label;
    }
    return subject.label;
}

/** The roles the shared `role.*` keys name (App\Models\Enums\Role). */
const roleKeys: Record<string, true> = {
    'role.super_admin': true,
    'role.manager': true,
    'role.order_operator': true,
    'role.inventory_staff': true,
    'role.accountant': true,
    'role.content_editor': true,
    'role.viewer': true,
};
