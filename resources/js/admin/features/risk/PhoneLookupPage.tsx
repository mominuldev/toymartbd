import { useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { ArrowsClockwiseIcon, ListChecksIcon, MagnifyingGlassIcon, PhoneIcon, ShieldWarningIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button, buttonClass } from '@/components/ui/Button';
import { dataTable } from '@/components/ui/DataTable';
import { QueryError } from '@/components/ui/QueryError';
import { StatusChip, type StatusTone } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { ApiError } from '@/lib/api';
import { usePhoneRisk, useRescore, type PhoneRisk } from './api';
import { CourierPanel, ListStatusBanner, OurRecord, OurScoreCard, RiskTabs, asLevel, crumbs, dhakaTime, taka, useWide } from './parts';

/** Rule 30, mirrored for instant feedback only — the server validates again. */
const BD_MOBILE = /^(?:\+?880|0)1[3-9]\d{8}$/;

/** How an order of this phone ended, as a chip tone. The status is the server's; this only picks a colour. */
const statusTone: Record<string, StatusTone> = {
    delivered: 'success',
    completed: 'success',
    cancelled: 'neutral',
    returned: 'danger',
    partially_returned: 'warning',
    on_hold: 'warning',
    pending: 'info',
    confirmed: 'info',
    processing: 'info',
    ready_to_ship: 'info',
    shipped: 'info',
};

/**
 * Phone lookup (Figma B · 06 premium 504:80850, 390: 504:82029): a one-line verdict from OUR
 * level, then our score and our record beside the couriers' record — separate blocks, never one
 * number (rule 24) — the staff list status, and the orders on this phone.
 */
export function PhoneLookupPage() {
    const { t } = useI18n();
    const [params, setParams] = useSearchParams();
    const phone = params.get('phone') ?? '';
    const [draft, setDraft] = useState(phone);
    const [invalid, setInvalid] = useState(false);
    const lookup = usePhoneRisk(phone);

    const submit = () => {
        const clean = draft.replace(/[\s-]/g, '');
        if (!BD_MOBILE.test(clean)) {
            setInvalid(true);
            return;
        }
        setInvalid(false);
        setParams({ phone: clean });
    };

    return (
        <div className="flex flex-col gap-6">
            <ContentHeader
                title={t('risk.lookup.title')}
                breadcrumb={crumbs(t, 'risk.tab.lookup')}
                subtitle={t('risk.lookup.subtitle')}
                actions={
                    <Link to="/risk/queue" className={buttonClass('outline', 'md')}>
                        <ListChecksIcon size={16} aria-hidden />
                        {t('risk.tab.queue')}
                    </Link>
                }
            />
            <RiskTabs />
            <form
                className="flex flex-col gap-2 rounded-md border border-line-default bg-surface p-5 shadow-e1"
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
            >
                <label htmlFor="risk-phone" className="sr-only">
                    {t('risk.lookup.label')}
                </label>
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                    {/* One focus ring, on the box (as AdminInput does) — the bare input inside draws none. */}
                    <span className="flex h-11 min-w-0 flex-1 items-center gap-2 rounded-sm border border-line-default bg-surface px-3 focus-within:border-line-focus focus-within:ring-1 focus-within:ring-line-focus focus-within:ring-inset has-aria-invalid:border-line-danger">
                        <PhoneIcon size={18} aria-hidden className="text-fg-secondary" />
                        <input
                            id="risk-phone"
                            value={draft}
                            onChange={(e) => setDraft(e.target.value)}
                            inputMode="tel"
                            autoComplete="off"
                            placeholder={t('risk.lookup.placeholder')}
                            aria-invalid={invalid}
                            aria-describedby="risk-phone-hint"
                            className="h-full min-w-0 flex-1 bg-transparent t-admin-body-lg tabular text-fg-primary outline-none placeholder:text-fg-tertiary focus-visible:shadow-none"
                        />
                    </span>
                    <Button type="submit" size="lg">
                        <MagnifyingGlassIcon size={20} aria-hidden />
                        {t('risk.lookup.submit')}
                    </Button>
                </div>
                <p id="risk-phone-hint" className={`t-admin-caption ${invalid ? 'text-feedback-danger' : 'text-fg-tertiary'}`} role={invalid ? 'alert' : undefined}>
                    {invalid ? t('risk.lookup.invalid') : t('risk.lookup.hint')}
                </p>
            </form>

            {phone === '' ? (
                <p className="t-admin-body text-fg-secondary">{t('risk.lookup.prompt')}</p>
            ) : lookup.isError ? (
                lookup.error instanceof ApiError && lookup.error.status === 422 ? (
                    <p role="alert" className="t-admin-body text-feedback-danger">
                        {t('risk.lookup.invalid')}
                    </p>
                ) : (
                    <QueryError error={lookup.error} onRetry={() => void lookup.refetch()} />
                )
            ) : !lookup.data ? (
                <p aria-busy="true" />
            ) : (
                <LookupResult data={lookup.data} phone={phone} />
            )}
        </div>
    );
}

const verdictFill = { low: 'bg-success-subtle', medium: 'bg-warning-subtle', high: 'bg-danger-subtle' } as const;
const verdictIcon = { low: 'text-feedback-success', medium: 'text-status-low-stock', high: 'text-feedback-danger' } as const;

function LookupResult({ data, phone }: { data: PhoneRisk; phone: string }) {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const wide = useWide();
    const rescore = useRescore();
    const level = asLevel(data.ours.level);
    const c = data.ours.counts;
    return (
        <>
            <section aria-labelledby="risk-verdict" className={`flex flex-col gap-4 rounded-md ${verdictFill[level]} p-5 sm:flex-row sm:items-center sm:p-6`}>
                <span className="hidden size-12 shrink-0 items-center justify-center rounded-full bg-surface sm:flex">
                    <ShieldWarningIcon size={24} aria-hidden className={verdictIcon[level]} />
                </span>
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <h2 id="risk-verdict" className="t-admin-page-title text-fg-primary">
                        {t(`risk.verdict.${level}` as MessageKey)}
                    </h2>
                    <p className="t-admin-body text-fg-primary">
                        {c.total_orders === 0
                            ? t('risk.verdict.noOrders')
                            : t('risk.verdict.body', { orders: c.total_orders, delivered: c.delivered, cancelled: c.cancelled, returned: c.returned + c.refused })}
                    </p>
                </div>
                <div className="flex flex-col gap-2 rounded-md bg-surface p-4 sm:w-72">
                    <p className="t-admin-num tabular text-fg-primary">{data.phone}</p>
                    {data.pii_masked && <p className="t-admin-caption text-fg-tertiary">{t('risk.lookup.masked')}</p>}
                    <div className="flex gap-2">
                        {!data.pii_masked && (
                            <a href={`tel:${data.phone}`} className={`${buttonClass('primary', wide ? 'sm' : 'md')} flex-1`}>
                                <PhoneIcon size={16} aria-hidden />
                                {t('risk.call.callNow')}
                            </a>
                        )}
                        {can(me, 'risk.verify') && (
                            <Button
                                variant="outline"
                                size={wide ? 'sm' : 'md'}
                                className="flex-1"
                                disabled={rescore.isPending}
                                onClick={() => rescore.mutate(phone, { onSuccess: () => notify(t('risk.lookup.rescored')) })}
                            >
                                <ArrowsClockwiseIcon size={16} aria-hidden />
                                {t('risk.lookup.rescore')}
                            </Button>
                        )}
                    </div>
                </div>
            </section>
            <div className="grid items-start gap-5 lg:grid-cols-2">
                <div className="flex flex-col gap-5">
                    <OurScoreCard ours={data.ours} />
                    <OurRecord ours={data.ours} />
                </div>
                <CourierPanel checkedAt={data.courier.checked_at} providers={data.courier.providers} />
            </div>
            <ListStatusBanner status={data.status} phone={data.pii_masked ? null : phone} />
            <section className={dataTable.card} aria-labelledby="risk-history">
                <h2 id="risk-history" className="px-5 py-4 t-admin-body-strong text-fg-primary">
                    {t('risk.lookup.history', { count: c.total_orders })}
                </h2>
                {data.recent_orders.length === 0 ? (
                    <p className="px-5 pb-4 t-admin-body text-fg-secondary">{t('risk.lookup.noOrders')}</p>
                ) : wide ? (
                    <table className={dataTable.table}>
                        <thead className={dataTable.head}>
                            <tr>
                                <th className={dataTable.th}>{t('risk.lookup.col.order')}</th>
                                <th className={dataTable.th}>{t('risk.lookup.col.placed')}</th>
                                <th className={`${dataTable.th} text-end`}>{t('risk.lookup.col.total')}</th>
                                <th className={dataTable.th}>{t('risk.lookup.col.status')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.recent_orders.map((o) => (
                                <tr key={o.id} className={dataTable.row}>
                                    <td className={`${dataTable.td} t-admin-mono`}>
                                        <Link to={`/orders/${o.id}`} className="text-fg-link hover:underline">
                                            {o.number}
                                        </Link>
                                    </td>
                                    <td className={`${dataTable.td} t-admin-body tabular`}>{o.placed_at ? dhakaTime(o.placed_at, locale) : '—'}</td>
                                    <td className={`${dataTable.td} t-admin-body tabular text-end`}>{taka(o.grand_total)}</td>
                                    <td className={dataTable.td}>
                                        <StatusChip label={t(`orders.status.${o.status}` as MessageKey)} tone={statusTone[o.status] ?? 'neutral'} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                ) : (
                    <ul className="flex flex-col gap-3 px-4 pb-4">
                        {data.recent_orders.map((o) => (
                            <li key={o.id} className="flex flex-col gap-2 rounded-md border border-line-default p-4">
                                <span className="flex items-center justify-between gap-2">
                                    <Link to={`/orders/${o.id}`} className="t-admin-mono text-fg-link hover:underline">
                                        {o.number}
                                    </Link>
                                    <span className="t-admin-num tabular text-fg-primary">{taka(o.grand_total)}</span>
                                </span>
                                <span className="flex items-center justify-between gap-2">
                                    <StatusChip label={t(`orders.status.${o.status}` as MessageKey)} tone={statusTone[o.status] ?? 'neutral'} />
                                    <span className="t-admin-caption tabular text-fg-secondary">{o.placed_at ? dhakaTime(o.placed_at, locale) : '—'}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </>
    );
}
