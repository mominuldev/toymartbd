import { useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { ArrowsClockwiseIcon, MagnifyingGlassIcon, PhoneIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button } from '@/components/ui/Button';
import { dataTable } from '@/components/ui/DataTable';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { ApiError } from '@/lib/api';
import { usePhoneRisk, useRescore } from './api';
import { CourierPanel, ListStatusBanner, OurCounts, OurScoreCard, RiskTabs, crumbs, dhakaTime, taka } from './parts';

/** Rule 30, mirrored for instant feedback only — the server validates again. */
const BD_MOBILE = /^(?:\+?880|0)1[3-9]\d{8}$/;

/**
 * Phone risk lookup (Figma B · 06 241:36699): OUR score with its breakdown and timestamp, the
 * staff list status, and the courier-verified record — three separately labelled blocks,
 * never one number (rule 24). A courier that is not connected says so, never 0 (rule 33b).
 */
export function PhoneLookupPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const [params, setParams] = useSearchParams();
    const phone = params.get('phone') ?? '';
    const [draft, setDraft] = useState(phone);
    const [invalid, setInvalid] = useState(false);
    const lookup = usePhoneRisk(phone);
    const rescore = useRescore();

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
            <ContentHeader title={t('risk.lookup.title')} breadcrumb={crumbs(t, 'risk.tab.lookup')} subtitle={t('risk.lookup.subtitle')} />
            <RiskTabs />
            <form
                className="flex flex-col gap-2 rounded-md border border-line-default bg-surface p-5 shadow-e1"
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
            >
                <label htmlFor="risk-phone" className="t-admin-body-strong text-fg-primary">
                    {t('risk.lookup.label')}
                </label>
                <div className="flex flex-wrap items-center gap-3">
                    <span className="flex h-12 min-w-64 flex-1 items-center gap-2 rounded-sm border-2 border-line-focus bg-surface px-3">
                        <PhoneIcon size={20} aria-hidden className="text-fg-secondary" />
                        <input
                            id="risk-phone"
                            value={draft}
                            onChange={(e) => setDraft(e.target.value)}
                            inputMode="tel"
                            autoComplete="off"
                            aria-invalid={invalid}
                            aria-describedby="risk-phone-hint"
                            className="h-full min-w-0 flex-1 bg-transparent t-admin-body-lg tabular text-fg-primary outline-none"
                        />
                    </span>
                    <Button type="submit" size="lg">
                        <MagnifyingGlassIcon size={18} aria-hidden />
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
                <>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="t-admin-section tabular text-fg-primary">{lookup.data.phone}</p>
                        <div className="flex items-center gap-2">
                            {lookup.data.pii_masked && <p className="t-admin-caption text-fg-tertiary">{t('risk.lookup.masked')}</p>}
                            {can(me, 'risk.verify') && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={rescore.isPending}
                                    onClick={() =>
                                        rescore.mutate(phone, {
                                            onSuccess: () => notify(t('risk.lookup.rescored')),
                                        })
                                    }
                                >
                                    <ArrowsClockwiseIcon size={16} aria-hidden />
                                    {t('risk.lookup.rescore')}
                                </Button>
                            )}
                        </div>
                    </div>
                    <div className="grid items-start gap-5 lg:grid-cols-2">
                        <div className="flex flex-col gap-5">
                            <OurScoreCard ours={lookup.data.ours} />
                            <OurCounts ours={lookup.data.ours} />
                        </div>
                        <CourierPanel checkedAt={lookup.data.courier.checked_at} providers={lookup.data.courier.providers} />
                    </div>
                    <ListStatusBanner status={lookup.data.status} phone={lookup.data.pii_masked ? null : phone} />
                    <section className={dataTable.card} aria-labelledby="risk-history">
                        <h2 id="risk-history" className="px-4 py-3 t-admin-section text-fg-primary">
                            {t('risk.lookup.history', {
                                count: lookup.data.ours.counts.total_orders,
                            })}
                        </h2>
                        {lookup.data.recent_orders.length === 0 ? (
                            <p className="px-4 pb-4 t-admin-body text-fg-secondary">{t('risk.lookup.noOrders')}</p>
                        ) : (
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
                                    {lookup.data.recent_orders.map((o) => (
                                        <tr key={o.id} className={dataTable.row}>
                                            <td className={`${dataTable.td} t-admin-mono`}>
                                                <Link to={`/orders/${o.id}`} className="text-fg-link hover:underline">
                                                    {o.number}
                                                </Link>
                                            </td>
                                            <td className={`${dataTable.td} t-admin-body tabular`}>{o.placed_at ? dhakaTime(o.placed_at, locale) : '—'}</td>
                                            <td className={`${dataTable.td} t-admin-body tabular text-end`}>{taka(o.grand_total)}</td>
                                            <td className={`${dataTable.td} t-admin-body`}>{t(`orders.status.${o.status}` as MessageKey)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </section>
                </>
            )}
        </div>
    );
}
