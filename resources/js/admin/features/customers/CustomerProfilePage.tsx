import { useId, useState, type FormEvent, type ReactNode } from 'react';
import { Link, useParams, useSearchParams } from 'react-router';
import { MagnifyingGlassIcon, PlusIcon, PushPinIcon, ShieldCheckIcon, WifiSlashIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { StatusChip, type StatusTone } from '@/components/ui/StatusChip';
import { TabButton, TabList } from '@/components/ui/Tabs';
import { Tag } from '@/components/ui/Tag';
import { useToast } from '@/components/ui/Toast';
import { ApiError } from '@/lib/api';
import { useAddCustomerNote, useCustomer, useCustomerNotes, useMessageConsents, useNotificationPreferences, type CustomerNote, type CustomerProfile } from './api';
import { Taka, count, dayMonth, dhakaDay, dhakaDayTime, dhakaDayYear, percentText, takaText } from './format';
import { BirthdayPanel } from './BirthdayPanel';
import { Panel, RiskPill, RiskStatusTag, SegmentTag, Stat, riskTint } from './parts';

type Order = CustomerProfile['orders'][number];
type Tab = 'orders' | 'notes';

const outcomeTone = (status: Order['status']): StatusTone => {
    if (status === 'delivered' || status === 'completed') return 'success';
    if (status === 'returned') return 'danger';
    if (status === 'partially_returned') return 'warning';
    if (status === 'cancelled') return 'neutral';
    return 'info';
};

/**
 * Customer profile (Figma B · 18 241:41374, Theme B): header with the contact line (masked
 * without customers.view_pii, rule 20) and actions; four StatCards (lifetime value from the
 * ledger, delivery success, orders, returns); Orders / Notes tabs; and the rail — profile (with
 * "Children" and "Birthday reminders" rows, P8 batch 3), kids & birthday reminders (edit + consent —
 * no frame; Panel pattern), addresses, OUR
 * risk score, the courier-evidence pointer (kept separate, rule 24) and the pinned note. Every figure is the server's (rule 1).
 */
/** Items an order has beyond the ones the profile lists (a count, not money). */
const extraItems = (o: { items_count: number; items: readonly unknown[] }): number => Math.max(0, o.items_count - o.items.length);

export function CustomerProfilePage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const id = Number(useParams().customerId);
    const customer = useCustomer(id);
    const [params, setParams] = useSearchParams();
    const tab: Tab = params.get('tab') === 'notes' ? 'notes' : 'orders';
    const setTab = (next: Tab) => setParams(next === 'orders' ? {} : { tab: next }, { replace: true });
    const prefs = useNotificationPreferences(id, can(me, 'customers.view'));
    const consents = useMessageConsents(id, can(me, 'customers.view'));
    const c = customer.data;

    if (customer.isError) {
        const missing = customer.error instanceof ApiError && customer.error.status === 404;
        return missing ? <EmptyState title={t('customers.profile.notFound')} /> : <QueryError error={customer.error} onRetry={() => void customer.refetch()} />;
    }
    if (!c) {
        return (
            <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                {t('common.loading')}
            </p>
        );
    }

    const contact = [c.phone, c.email, c.created_at ? t('customers.profile.since', { date: dhakaDayYear(c.created_at, locale) }) : null, t('customers.profile.prefers', { language: t(`customers.language.${c.preferred_language}` as MessageKey) })]
        .filter(Boolean)
        .join(' · ');
    const s = c.stats;
    const finished = s.delivered_orders_count + s.returned_orders_count;

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('nav.group.sell') }, { label: t('customers.crumb'), to: '/customers' }, { label: c.name }]}
                title={c.name}
                subtitle={<span className="tabular">{contact}</span>}
                actions={
                    <>
                        {can(me, 'risk.view') && !c.pii_masked && (
                            <Link to={`/risk/lookup?phone=${encodeURIComponent(c.phone)}`} className={buttonClass('outline')}>
                                <MagnifyingGlassIcon size={16} aria-hidden />
                                {t('customers.profile.lookup')}
                            </Link>
                        )}
                        {can(me, 'orders.create') && (
                            <Link to="/orders/new" className={buttonClass('primary')}>
                                <PlusIcon size={16} aria-hidden />
                                {t('customers.profile.newOrder')}
                            </Link>
                        )}
                    </>
                }
            />
            {c.pii_masked && <p className="t-admin-caption text-fg-secondary">{t('customers.profile.pii')}</p>}

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Stat
                    label={t('customers.stat.ltv')}
                    value={<Taka amount={s.lifetime_value} signClass="t-admin-currency-num-lg" />}
                    hint={s.average_order_value === null ? t('customers.stat.ltvHintNone') : t('customers.stat.ltvHint', { orders: count(s.delivered_orders_count), aov: takaText(s.average_order_value) })}
                />
                <Stat
                    label={t('customers.stat.delivery')}
                    value={s.delivery_success_rate === null ? <span className="text-fg-tertiary">{t('customers.none')}</span> : percentText(s.delivery_success_rate)}
                    hint={t('customers.stat.deliveryHint', { delivered: count(s.delivered_orders_count), total: count(finished) })}
                />
                <Stat label={t('customers.stat.orders')} value={count(s.orders_count)} hint={t('customers.stat.ordersHint', { cancelled: count(s.cancelled_orders_count) })} />
                <Stat label={t('customers.stat.returns')} value={count(s.returned_orders_count)} hint={t('customers.stat.returnsHint', { amount: takaText(s.refunds) })} />
            </div>

            <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20.5rem]">
                <div className="flex min-w-0 flex-col gap-4">
                    <TabList label={t('customers.tabs')}>
                        <TabButton active={tab === 'orders'} label={t('customers.tab.orders')} count={s.orders_count + s.cancelled_orders_count} onClick={() => setTab('orders')} />
                        <TabButton active={tab === 'notes'} label={t('customers.tab.notes')} count={c.notes_count} onClick={() => setTab('notes')} />
                    </TabList>
                    {tab === 'orders' ? <OrdersTable customer={c} /> : <Notes id={c.id} canAdd={can(me, 'customers.update')} />}
                </div>

                <aside className="flex min-w-0 flex-col gap-4">
                    <Panel title={t('customers.card.profile')}>
                        <div className="flex flex-wrap gap-1.5">
                            <SegmentTag segment={c.segment} />
                            {c.risk && <RiskStatusTag status={c.risk.status.value} />}
                            {c.tags.map((tag) => (
                                <Tag key={tag} label={tag} tone="lavender" />
                            ))}
                            {c.children_count > 0 && <Tag label={t(c.children_count === 1 ? 'customers.kids.tagOne' : 'customers.kids.tag', { count: c.children_count })} tone="lavender" />}
                        </div>
                        <dl className="flex flex-col gap-3">
                            <Row label={t('customers.card.account')} value={t(c.has_account ? 'customers.card.accountYes' : 'customers.card.accountNo')} />
                            <Row label={t('customers.card.source')} value={t(`customers.source.${c.source}` as MessageKey)} />
                            <Row label={t('customers.card.language')} value={t(`customers.language.${c.preferred_language}` as MessageKey)} />
                            {c.first_order_at && <Row label={t('customers.card.firstOrder')} value={dhakaDayYear(c.first_order_at, locale)} />}
                            {/* Figma B · 18 241:41502 / 241:41505 — children's data: names and dates only with customers.view_pii. */}
                            <Row label={t('customers.card.children')} value={<span className="tabular">{childrenSummary(c, t)}</span>} />
                            <Row label={t('customers.card.reminders')} value={<span className="tabular">{remindersSummary(c, consents.data?.birthday.consented ?? false, t, locale)}</span>} />
                        </dl>
                        {prefs.data && (
                            <div className="flex flex-col gap-2 border-t border-line-default pt-3">
                                <p className="t-admin-overline text-fg-secondary">{t('customers.prefs.title')}</p>
                                <dl className="flex flex-col gap-1.5">
                                    {(['order_updates', 'marketing'] as const).map((category) => (
                                        <Row
                                            key={category}
                                            label={t(`customers.prefs.category.${category}` as MessageKey)}
                                            value={
                                                prefs.data
                                                    .filter((p) => p.category === category && p.enabled)
                                                    .map((p) => t(`customers.prefs.channel.${p.channel}` as MessageKey))
                                                    .join(' · ') || t('customers.prefs.off')
                                            }
                                        />
                                    ))}
                                </dl>
                            </div>
                        )}
                    </Panel>

                    <BirthdayPanel customer={c} canUpdate={can(me, 'customers.update')} />

                    <Panel title={t('customers.addresses.title')} subtitle={t('customers.addresses.count', { count: count(c.addresses.length) })}>
                        {c.addresses.length === 0 ? (
                            <p className="t-admin-body text-fg-tertiary">{t('customers.addresses.empty')}</p>
                        ) : (
                            <ul className="flex flex-col gap-4">
                                {c.addresses.map((a) => (
                                    <li key={a.id} className="flex flex-col gap-1">
                                        <p className="t-admin-body-strong text-fg-primary">
                                            {a.label ?? t(`customers.addressType.${a.type}` as MessageKey)} {a.is_default_shipping && t('customers.addresses.default')}
                                        </p>
                                        <p className="t-admin-body text-fg-secondary">
                                            {[a.address_line, a.area, a.upazila, locale === 'bn' && a.district_bn ? a.district_bn : a.district].filter(Boolean).join(', ')}
                                            {a.landmark ? ` · ${a.landmark}` : ''}
                                        </p>
                                        {c.pii_masked && <p className="t-admin-caption text-fg-tertiary">{t('customers.addresses.masked')}</p>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    {c.risk && <RiskCard risk={c.risk} />}
                    {c.risk && <CourierEvidence phone={c.pii_masked ? null : c.phone} />}

                    {c.pinned_note && (
                        <div className="flex flex-col gap-1 rounded-md bg-brand-subtle p-4">
                            <p className="t-admin-body text-fg-primary">{c.pinned_note.body}</p>
                            <p className="t-admin-caption text-fg-secondary">
                                {t('customers.notes.by', { name: c.pinned_note.user_name ?? t('customers.notes.system'), date: c.pinned_note.created_at ? dhakaDayYear(c.pinned_note.created_at, locale) : '' })}
                            </p>
                        </div>
                    )}
                </aside>
            </div>
        </section>
    );
}

type T = ReturnType<typeof useI18n>['t'];

/** "Arian (6) · Mehrin (3)" (Figma 241:41504); a count only without customers.view_pii. */
function childrenSummary(c: CustomerProfile, t: T): string {
    if (c.children_count === 0) return t('customers.kids.noneShort');
    if (c.children === null) return t('customers.kids.hiddenShort', { count: c.children_count });
    return c.children.map((k) => (k.age === null ? (k.name ?? t('customers.kids.unnamed')) : `${k.name ?? t('customers.kids.unnamed')} (${k.age})`)).join(' · ');
}

/** "12 Mar · 04 Aug" (Figma 241:41507) while the consent is on; "Off" otherwise. */
function remindersSummary(c: CustomerProfile, consented: boolean, t: T, locale: string): string {
    if (!consented || c.children_count === 0) return t('customers.prefs.off');
    if (c.children === null) return t('customers.prefs.on');
    return c.children.map((k) => dayMonth(k.birth_month, k.birth_day, locale)).join(' · ');
}

function Row({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <dt className="t-admin-body text-fg-secondary">{label}</dt>
            <dd className="t-admin-body-strong text-end text-fg-primary">{value}</dd>
        </div>
    );
}

function OrdersTable({ customer }: { customer: CustomerProfile }) {
    const { t, locale } = useI18n();
    if (customer.orders.length === 0) return <EmptyState title={t('customers.orders.empty')} />;
    return (
        <div className="flex flex-col gap-2">
            <div className={dataTable.card}>
                <div className="overflow-x-auto">
                    <table className={dataTable.table}>
                        <thead className={dataTable.head}>
                            <tr className="border-b border-line-strong">
                                <th scope="col" className={dataTable.th}>
                                    {t('customers.orders.col.order')}
                                </th>
                                <th scope="col" className={dataTable.th}>
                                    {t('customers.orders.col.placed')}
                                </th>
                                <th scope="col" className={dataTable.th}>
                                    {t('customers.orders.col.items')}
                                </th>
                                <th scope="col" className={`${dataTable.th} text-end`}>
                                    {t('customers.orders.col.total')}
                                </th>
                                <th scope="col" className={dataTable.th}>
                                    {t('customers.orders.col.outcome')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {customer.orders.map((o) => (
                                <tr key={o.id} className={dataTable.row}>
                                    <td className={`${dataTable.td} t-admin-mono whitespace-nowrap`}>
                                        <Link to={`/orders/${o.id}`} className="hover:text-fg-link hover:underline">
                                            {o.number}
                                        </Link>
                                    </td>
                                    <td className={`${dataTable.td} t-admin-body tabular whitespace-nowrap`}>{o.placed_at ? dhakaDay(o.placed_at, locale) : t('customers.none')}</td>
                                    <td className={`${dataTable.td} t-admin-body`}>
                                        {o.items.map((i) => `${locale === 'bn' && i.name_bn ? i.name_bn : i.name} × ${i.quantity}`).join(', ')}
                                        {extraItems(o) ? <span className="text-fg-tertiary"> {t('customers.orders.itemsMore', { count: extraItems(o) })}</span> : null}
                                    </td>
                                    <td className={`${dataTable.td} t-admin-num text-end`}>
                                        <Taka amount={o.grand_total} />
                                    </td>
                                    <td className={dataTable.td}>
                                        <StatusChip
                                            tone={outcomeTone(o.status)}
                                            label={
                                                (o.status === 'delivered' || o.status === 'completed') && o.delivered_at
                                                    ? t('customers.orders.deliveredOn', { date: dhakaDay(o.delivered_at, locale) })
                                                    : t(`orders.status.${o.status}` as MessageKey)
                                            }
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            {customer.orders_truncated && (
                <Link to="/orders" className="t-admin-body text-fg-link hover:underline">
                    {t('customers.orders.more')}
                </Link>
            )}
        </div>
    );
}

/** Figma RiskScoreCard "Our assessment" 197:858 — OUR score only, with when we computed it (rule 24). */
function RiskCard({ risk }: { risk: NonNullable<CustomerProfile['risk']> }) {
    const { t, locale } = useI18n();
    const ours = risk.ours;
    return (
        <section className="flex flex-col gap-3 rounded-md border border-line-default border-t-4 border-t-brand bg-surface p-5 shadow-e1">
            <div className="flex flex-col gap-0.5">
                <p className="t-admin-overline text-fg-secondary">{t('customers.riskCard.overline')}</p>
                <h2 className="t-admin-section text-fg-primary">{t('customers.riskCard.title')}</h2>
                <p className="flex items-center gap-1 t-admin-caption text-fg-secondary">
                    <ShieldCheckIcon size={14} aria-hidden />
                    {ours.stored && ours.score_computed_at ? t('customers.riskCard.computed', { time: dhakaDayTime(ours.score_computed_at, locale) }) : t('customers.riskCard.notStored')}
                </p>
            </div>
            <div className="flex items-center gap-4">
                <div className="flex shrink-0 flex-col items-center">
                    <span className={`t-admin-num-lg tabular ${riskTint(ours.level)}`}>{ours.internal_score}</span>
                    <span className="t-admin-caption whitespace-nowrap text-fg-secondary">{t('customers.riskCard.of')}</span>
                </div>
                <div className="flex min-w-0 flex-col items-start gap-1.5">
                    <RiskPill level={ours.level} score={ours.internal_score} />
                    <p className="t-admin-caption text-fg-secondary tabular">
                        {t('customers.riskCard.counts', { orders: count(ours.counts.total_orders), delivered: count(ours.counts.delivered), cancelled: count(ours.counts.cancelled), returned: count(ours.counts.returned) })}
                    </p>
                </div>
            </div>
            <p className="t-admin-caption text-fg-secondary">{t('customers.riskCard.list', { status: t(`customers.riskStatus.${risk.status.value}` as MessageKey) })}</p>
        </section>
    );
}

/** Figma "External evidence" card: a dashed panel that points to the courier record — never merged with our score. */
function CourierEvidence({ phone }: { phone: string | null }) {
    const { t } = useI18n();
    return (
        <section className="flex flex-col gap-3 rounded-md border border-dashed border-line-strong bg-surface p-5">
            <div className="flex flex-col gap-0.5">
                <p className="t-admin-overline text-fg-secondary">{t('customers.courierCard.overline')}</p>
                <h2 className="t-admin-section text-fg-primary">{t('customers.courierCard.title')}</h2>
            </div>
            <div className="flex flex-col items-center gap-2 rounded-md bg-subtle p-4 text-center">
                <WifiSlashIcon size={24} aria-hidden className="text-fg-secondary" />
                <p className="t-admin-body text-fg-secondary">{t('customers.courierCard.body')}</p>
                {phone && (
                    <Link to={`/risk/lookup?phone=${encodeURIComponent(phone)}`} className={buttonClass('outline', 'sm')}>
                        {t('customers.courierCard.link')}
                    </Link>
                )}
            </div>
        </section>
    );
}

function Notes({ id, canAdd }: { id: number; canAdd: boolean }) {
    const { t, locale } = useI18n();
    const notes = useCustomerNotes(id);
    const add = useAddCustomerNote(id);
    const toast = useToast();
    const [body, setBody] = useState('');
    const [pinned, setPinned] = useState(false);
    const ids = useId();

    const submit = (e: FormEvent) => {
        e.preventDefault();
        add.mutate(
            { body, is_pinned: pinned },
            {
                onSuccess: () => {
                    setBody('');
                    setPinned(false);
                    toast.notify(t('customers.notes.saved'));
                },
                onError: (error) => toast.notify(error instanceof ApiError ? (error.fieldError('body') ?? error.problem.title) : t('error.title'), 'danger'),
            },
        );
    };

    return (
        <div className="flex flex-col gap-4">
            {canAdd && (
                <form onSubmit={submit} className="flex flex-col gap-2 rounded-md border border-line-default bg-surface p-4">
                    <label htmlFor={`${ids}-body`} className="t-admin-body-strong text-fg-primary">
                        {t('customers.notes.label')}
                    </label>
                    <textarea
                        id={`${ids}-body`}
                        value={body}
                        onChange={(e) => setBody(e.target.value)}
                        maxLength={2000}
                        rows={3}
                        placeholder={t('customers.notes.placeholder')}
                        className="rounded-sm border border-line-default bg-surface p-3 t-admin-body text-fg-primary placeholder:text-fg-tertiary focus:border-line-focus focus:ring-1 focus:ring-line-focus focus:ring-inset"
                    />
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <label className="inline-flex items-center gap-2 t-admin-body text-fg-primary">
                            <input type="checkbox" checked={pinned} onChange={(e) => setPinned(e.target.checked)} className="size-4 accent-brand" />
                            {t('customers.notes.pin')}
                        </label>
                        <Button type="submit" disabled={body.trim() === '' || add.isPending}>
                            {t('customers.notes.save')}
                        </Button>
                    </div>
                    <p className="t-admin-caption text-fg-tertiary">{t('customers.notes.appendOnly')}</p>
                </form>
            )}
            {notes.isError ? (
                <QueryError error={notes.error} onRetry={() => void notes.refetch()} />
            ) : !notes.data ? (
                <p className="t-admin-body text-fg-tertiary">{t('common.loading')}</p>
            ) : notes.data.length === 0 ? (
                <p className="t-admin-body text-fg-tertiary">{t('customers.notes.empty')}</p>
            ) : (
                <ul className="flex flex-col gap-3" aria-label={t('customers.notes.title')}>
                    {notes.data.map((note: CustomerNote) => (
                        <li key={note.id} className="flex flex-col gap-1 rounded-md border border-line-default bg-surface p-4">
                            {note.is_pinned && (
                                <span className="inline-flex items-center gap-1 t-admin-caption-strong text-fg-brand">
                                    <PushPinIcon size={14} aria-hidden />
                                    {t('customers.notes.pinned')}
                                </span>
                            )}
                            <p className="t-admin-body whitespace-pre-line text-fg-primary">{note.body}</p>
                            <p className="t-admin-caption text-fg-secondary">
                                {t('customers.notes.by', { name: note.user_name ?? t('customers.notes.system'), date: note.created_at ? dhakaDayTime(note.created_at, locale) : '' })}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
