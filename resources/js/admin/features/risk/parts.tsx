import { useEffect, useState, type ReactNode } from 'react';
import { Link } from 'react-router';
import { EyeIcon, PlugsIcon, ProhibitIcon, SealCheckIcon, ShieldWarningIcon, TruckIcon, WifiSlashIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { TabLink, TabList } from '@/components/ui/Tabs';
import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { takaText } from '@/lib/money';
import { ApiError } from '@/lib/api';
import { factorCodes, holdReasons, useSetPhoneStatus, type CourierRecord, type ListStatus, type ListStatusValue, type OurScore, type RiskFactorRow, type RiskLevel } from './api';

/*
 * Fraud & risk premium pass (Figma 504:75986, 2026-11-17): plain words first. Every number on
 * these screens is the server's — the browser only words and draws it (rules 1, 24). Our score
 * and the courier record stay separate blocks; a courier that cannot answer is never a zero
 * (rule 33b); nothing here cancels an order by itself (rule 23).
 */

/** "08 Nov 2026, 2:18 pm" in Dhaka time, Western digits (rules 11, 36). */
export function dhakaTime(iso: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', {
        timeZone: 'Asia/Dhaka',
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        numberingSystem: 'latn',
    }).format(new Date(iso));
}

/** "6260.00" → "৳6,260" — the admin's one formatter (lib/money, lakh grouping). */
export const taka = (decimal: string): string => takaText(decimal);

export function asLevel(level: string | null | undefined): RiskLevel {
    return level === 'high' || level === 'medium' ? level : 'low';
}

/** Below 1280 the queue opens the chosen order in place (Figma 390 frame 504:81866); jsdom has no matchMedia and lays out wide. */
export function useWide(): boolean {
    const query = '(min-width: 80rem)';
    const read = () => typeof window === 'undefined' || typeof window.matchMedia !== 'function' || window.matchMedia(query).matches;
    const [wide, setWide] = useState(read);
    useEffect(() => {
        if (typeof window.matchMedia !== 'function') return;
        const list = window.matchMedia(query);
        const update = () => setWide(list.matches);
        list.addEventListener('change', update);
        return () => list.removeEventListener('change', update);
    }, []);
    return wide;
}

const levelLook: Record<RiskLevel, { fill: string; icon: string; bar: string }> = {
    low: { fill: 'bg-success-subtle', icon: 'text-feedback-success', bar: 'bg-feedback-success' },
    medium: { fill: 'bg-warning-subtle', icon: 'text-status-low-stock', bar: 'bg-feedback-warning' },
    high: { fill: 'bg-danger-subtle', icon: 'text-feedback-danger', bar: 'bg-feedback-danger' },
};

/** Figma RiskPill 190:405: shield + level word + number on the level's tinted fill; the colour only reinforces. */
export function RiskPill({ level, score }: { level: RiskLevel; score: number }) {
    const { t } = useI18n();
    const look = levelLook[level];
    return (
        <span className={`inline-flex h-6 shrink-0 items-center gap-1 rounded-sm ${look.fill} px-2 t-admin-caption-strong whitespace-nowrap text-fg-primary`}>
            <ShieldWarningIcon size={14} aria-hidden className={look.icon} />
            {t(`risk.level.${level}` as MessageKey, { score })}
        </span>
    );
}

/** The section's tab strip: orders to check · phone lookup · risk rules. */
export function RiskTabs({ waiting }: { waiting?: number }) {
    const { t } = useI18n();
    return (
        <TabList label={t('risk.tabs')}>
            <TabLink to="/risk/queue" label={t('risk.tab.queue')} count={waiting} />
            <TabLink to="/risk/lookup" label={t('risk.tab.lookup')} />
            <TabLink to="/risk/rules" label={t('risk.tab.rules')} />
        </TabList>
    );
}

export const crumbs = (t: (k: MessageKey) => string, page: MessageKey) => [{ label: t('risk.crumb.deliver') }, { label: t('risk.crumb.section'), to: '/risk/queue' }, { label: t(page) }];

export const cardClass = 'flex flex-col gap-4 rounded-md border border-line-default bg-surface p-5 shadow-e1';

/** A thin progress bar. `percent` is a share the server already gave us (a ratio, a contribution of a weight) — display only. */
export function Meter({ percent, tone, size = 'sm' }: { percent: number; tone: string; size?: 'sm' | 'md' }) {
    const width = Math.max(0, Math.min(100, percent));
    return (
        <span aria-hidden className={`block w-full overflow-hidden rounded-full bg-subtle ${size === 'md' ? 'h-2' : 'h-1.5'}`}>
            <span className={`block h-full rounded-full ${tone}`} style={{ width: `${width}%` }} />
        </span>
    );
}

/** "4/7" → ['4', '7'] — the scorer's own "count/total" value, worded, never recomputed. */
function pair(value: string | undefined): [string, string] {
    const [a = '0', b = '0'] = (value ?? '').split('/');
    return [a, b];
}

/** One factor of our score as a plain sentence ("Cancelled 4 of their 7 past orders"). */
export function factorSentence(f: Partial<RiskFactorRow>, t: (k: MessageKey, p?: Record<string, string | number>) => string): string {
    const code = f.code ?? '';
    if (!(factorCodes as readonly string[]).includes(code)) return code;
    if (code === 'cancellation_rate' || code === 'courier_return_rate') {
        const [a, b] = pair(f.value);
        return t(`risk.why.${code}` as MessageKey, { a, b });
    }
    return t(`risk.why.${code}` as MessageKey, { n: f.value ?? '' });
}

/** The single line a held order shows in the list: why it is waiting, in words. */
export function holdSentence(holds: string[], signals: Partial<RiskFactorRow>[], t: (k: MessageKey, p?: Record<string, string | number>) => string): string | null {
    if (holds.includes('blacklisted')) return t('risk.holdWhy.blacklisted');
    if (holds.includes('watchlist')) return t('risk.holdWhy.watchlist');
    if (holds.includes('high_risk_score')) {
        const top = [...signals].filter((s) => (s.contribution ?? 0) > 0).sort((a, b) => (b.contribution ?? 0) - (a.contribution ?? 0))[0];
        if (top) return factorSentence(top, t);
        return t('risk.holdWhy.high_risk_score');
    }
    const first = holds.find((h) => (holdReasons as readonly string[]).includes(h));
    return first ? t(`risk.holdWhy.${first}` as MessageKey) : null;
}

/**
 * "Why this order is paused" (Figma 507:76930): our level, one plain sentence, then each factor
 * that added points as "+25 · Cancelled 4 of their 7 past orders". The server's contributions.
 */
export function WhyPaused({ score, level, signals, holds }: { score: number | null; level: RiskLevel; signals: Partial<RiskFactorRow>[]; holds: string[] }) {
    const { t } = useI18n();
    const fired = signals.filter((s) => (s.contribution ?? 0) > 0);
    const extra = holds.filter((h) => h !== 'high_risk_score' && (holdReasons as readonly string[]).includes(h));
    return (
        <section aria-labelledby="risk-why" className={`flex flex-col gap-3 rounded-md ${levelLook[level].fill} p-5`}>
            <div className="flex items-center gap-2">
                <ShieldWarningIcon size={20} aria-hidden className={levelLook[level].icon} />
                <h3 id="risk-why" className="flex-1 t-admin-body-strong text-fg-primary">
                    {t('risk.why.title')}
                </h3>
                {score !== null && <RiskPill level={level} score={score} />}
            </div>
            <p className="t-admin-body text-fg-primary">{t(`risk.why.lead.${level}` as MessageKey, { score: score ?? 0 })}</p>
            {(fired.length > 0 || extra.length > 0) && (
                <ul className="flex flex-col gap-2">
                    {fired.map((f) => (
                        <li key={f.code} className="flex items-center gap-3">
                            <span className="inline-flex h-5.5 w-11 shrink-0 items-center justify-center rounded-xs bg-surface t-admin-caption-strong tabular text-feedback-danger">
                                +{f.contribution}
                            </span>
                            <span className="t-admin-body text-fg-primary">{factorSentence(f, t)}</span>
                        </li>
                    ))}
                    {extra.map((h) => (
                        <li key={h} className="flex items-center gap-3">
                            <span className="inline-flex h-5.5 w-11 shrink-0 items-center justify-center rounded-xs bg-surface">
                                <ShieldWarningIcon size={14} aria-hidden className="text-feedback-danger" />
                            </span>
                            <span className="t-admin-body text-fg-primary">{t(`risk.holdWhy.${h}` as MessageKey)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/** "From our orders": our own counts as one bar — delivered · cancelled · returned — labelled as ours. */
export function OurRecord({ ours }: { ours: OurScore }) {
    const { t } = useI18n();
    const c = ours.counts;
    const parts: [MessageKey, number, string, string][] = [
        ['risk.record.delivered', c.delivered, 'bg-feedback-success', 'bg-feedback-success'],
        ['risk.record.cancelled', c.cancelled, 'bg-feedback-danger', 'bg-feedback-danger'],
        ['risk.record.returned', c.returned + c.refused, 'bg-feedback-warning', 'bg-feedback-warning'],
    ];
    const total = parts.reduce((s, p) => s + p[1], 0);
    return (
        <section aria-labelledby="risk-ours-record" className="flex flex-col gap-3 rounded-md border border-line-default bg-surface p-5">
            <p id="risk-ours-record" className="t-admin-overline text-fg-secondary">
                {t('risk.record.ours')}
            </p>
            <p className="t-admin-body-strong tabular text-fg-primary">{c.total_orders === 0 ? t('risk.record.none') : t('risk.record.orders', { count: c.total_orders })}</p>
            {total > 0 && (
                <span aria-hidden className="flex h-2 w-full gap-0.5 overflow-hidden rounded-full">
                    {parts
                        .filter((p) => p[1] > 0)
                        .map(([key, n, bar]) => (
                            <span key={key} className={`h-full ${bar}`} style={{ flexGrow: n }} />
                        ))}
                </span>
            )}
            <ul className="flex flex-wrap gap-x-4 gap-y-1">
                {parts.map(([key, n, , dot]) => (
                    <li key={key} className="flex items-center gap-1.5 t-admin-caption tabular text-fg-secondary">
                        <span aria-hidden className={`size-2 rounded-full ${dot}`} />
                        {t(key, { count: n })}
                    </li>
                ))}
                {c.failed_delivery > 0 && <li className="t-admin-caption tabular text-fg-secondary">{t('risk.record.failed', { count: c.failed_delivery })}</li>}
            </ul>
            <p className="t-admin-caption text-fg-tertiary">{t('risk.record.oursNote')}</p>
        </section>
    );
}

/** Reasons the owner can fix in courier settings (Pathao's `no_official_api` is not one). */
const fixableReasons = ['no_merchant_account', 'credentials_rejected'];
const knownReasons = ['no_merchant_account', 'credentials_rejected', 'no_official_api', 'driver_missing', 'provider_error'];

/** A provider's 0–1 ratio string as a whole percentage — display only. */
function percentOf(ratio: string | null): number | null {
    return ratio === null ? null : Math.round(Number(ratio) * 1000) / 10;
}

/** One connected courier: parcels and reports as tiles, delivered and cancelled as bars with a plain hint. */
function CourierCard({ p, compact }: { p: CourierRecord; compact: boolean }) {
    const { t, locale } = useI18n();
    const name = locale === 'bn' && p.name_bn ? p.name_bn : p.name;
    const delivered = percentOf(p.delivery_ratio);
    const cancelled = percentOf(p.cancellation_ratio);
    const parcels = p.total_parcels ?? p.volume;
    const bars: [MessageKey, number | null, number | null, string, MessageKey][] = [
        ['risk.courier.delivered', delivered, p.total_delivered, 'bg-feedback-success', 'risk.courier.deliveredHint'],
        ['risk.courier.cancelled', cancelled, p.total_cancelled, 'bg-feedback-danger', 'risk.courier.cancelledHint'],
    ];
    return (
        <div className="flex flex-col gap-3 rounded-md border border-line-default bg-surface p-4">
            <div className="flex items-center gap-2">
                <TruckIcon size={18} aria-hidden className="text-fg-secondary" />
                <p className="flex-1 t-admin-body-strong text-fg-primary">{name}</p>
                <StatusChip label={t('risk.courier.connected')} tone="success" />
            </div>
            {!compact && (
                <dl className="grid grid-cols-2 gap-3">
                    <div className="rounded-sm bg-subtle px-3 py-2">
                        <dt className="t-admin-caption text-fg-secondary">{t('risk.courier.parcels')}</dt>
                        <dd className="t-admin-num tabular text-fg-primary">{parcels ?? '—'}</dd>
                    </div>
                    <div className="rounded-sm bg-subtle px-3 py-2">
                        <dt className="t-admin-caption text-fg-secondary">{t('risk.courier.fraud')}</dt>
                        <dd className="t-admin-num tabular text-fg-primary">{p.total_fraud_reports ?? '—'}</dd>
                    </div>
                </dl>
            )}
            {bars.map(([label, pct, count, tone, hint]) => (
                <div key={label} className="flex flex-col gap-1">
                    <p className="flex items-center justify-between gap-2">
                        <span className="t-admin-body text-fg-primary">{t(label)}</span>
                        <span className="t-admin-body-strong tabular text-fg-primary">{pct === null ? (count ?? '—') : `${pct}%${count === null ? '' : ` · ${count}`}`}</span>
                    </p>
                    {pct !== null && <Meter percent={pct} tone={tone} size="md" />}
                    {!compact && pct !== null && <p className="t-admin-caption text-fg-tertiary">{t(hint, { percent: pct })}</p>}
                </div>
            ))}
            <p className="t-admin-caption tabular text-fg-tertiary">
                {compact && parcels !== null ? `${t('risk.courier.parcelsShort', { parcels: String(parcels) })} · ` : ''}
                {compact && p.total_fraud_reports !== null ? `${t('risk.courier.reportsShort', { count: p.total_fraud_reports })} · ` : ''}
                {t('risk.courier.fetchedAt', { when: p.fetched_at ? dhakaTime(p.fetched_at, locale) : '—' })}
            </p>
        </div>
    );
}

/**
 * "What the couriers say" (Figma 504:80850 right column): each courier's OFFICIAL record, or why it
 * has none. With no courier answering, one explicit "not connected" state — never a zero (rule
 * 33b); once one answers, the rest are a line each. Never blended with our score (rule 24).
 */
export function CourierPanel({ checkedAt, providers, compact = false }: { checkedAt: string; providers: CourierRecord[]; compact?: boolean }) {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const connected = providers.filter((p) => p.connected);
    const missing = providers.filter((p) => !p.connected);
    const name = (p: CourierRecord) => (locale === 'bn' && p.name_bn ? p.name_bn : p.name);
    const reason = (p: CourierRecord) => t(`risk.courier.reason.${knownReasons.includes(p.reason ?? '') ? p.reason : 'other'}` as MessageKey, { name: name(p) });
    const settingsLink = can(me, 'settings.manage_integrations') && (
        <Link
            to="/integrations/couriers"
            className="inline-flex h-8 items-center gap-1.5 rounded-sm border border-line-default bg-surface px-3 t-admin-button-sm text-fg-primary hover:bg-subtle"
        >
            <PlugsIcon size={16} aria-hidden />
            {t('risk.courier.settings')}
        </Link>
    );
    return (
        <section className="flex flex-col gap-4 rounded-md border border-dashed border-line-strong bg-surface p-5" aria-labelledby="risk-courier">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 id="risk-courier" className="t-admin-overline text-fg-secondary">
                    {t('risk.courier.title')}
                </h2>
                <p className="t-admin-caption text-fg-tertiary">{t('risk.courier.checked', { when: dhakaTime(checkedAt, locale) })}</p>
            </div>
            {connected.map((p) => (
                <CourierCard key={p.provider} p={p} compact={compact} />
            ))}
            {missing.length > 0 && connected.length === 0 && (
                <div className="flex flex-col items-center gap-2 rounded-md bg-subtle px-6 py-6 text-center">
                    <WifiSlashIcon size={28} aria-hidden className="text-fg-secondary" />
                    <p className="t-admin-section text-fg-primary">{t('risk.courier.notConnected')}</p>
                    <p className="t-admin-body text-fg-secondary">{t('risk.courier.notConnectedBody')}</p>
                    <ul className="flex flex-col gap-1">
                        {missing.map((p) => (
                            <li key={p.provider} className="t-admin-caption text-fg-secondary">
                                {reason(p)}
                            </li>
                        ))}
                    </ul>
                    {settingsLink}
                </div>
            )}
            {/* Another courier did answer: no "nothing is connected" state, just why the rest are absent. */}
            {missing.length > 0 && connected.length > 0 && (
                <div className="flex flex-col items-start gap-2">
                    <ul className="flex w-full flex-col gap-2">
                        {missing.map((p) => (
                            <li key={p.provider} className="flex items-center gap-3 rounded-md bg-subtle px-4 py-3">
                                <WifiSlashIcon size={18} aria-hidden className="shrink-0 text-fg-tertiary" />
                                <span className="t-admin-caption text-fg-secondary">{reason(p)}</span>
                            </li>
                        ))}
                    </ul>
                    {missing.some((p) => fixableReasons.includes(p.reason ?? '')) && settingsLink}
                </div>
            )}
        </section>
    );
}

/**
 * "Our score" (Figma 504:80850 left column): the number, our level, a bar, and each factor that
 * added points as a sentence with its share of the factor's weight. Factors that added nothing
 * are named in one line. The server's numbers; never a courier figure (rule 24).
 */
export function OurScoreCard({ ours }: { ours: OurScore }) {
    const { t, locale } = useI18n();
    const level = asLevel(ours.level);
    const fired = ours.breakdown.filter((f) => f.contribution > 0);
    const quiet = ours.breakdown.filter((f) => f.contribution === 0);
    const when = ours.score_computed_at ? dhakaTime(ours.score_computed_at, locale) : null;
    return (
        <section className={cardClass} aria-labelledby="risk-ours">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 id="risk-ours" className="t-admin-overline text-fg-secondary">
                    {t('risk.ours.title')}
                </h2>
                <p className="t-admin-caption text-fg-tertiary">{ours.stored && when ? t('risk.ours.provenance', { when }) : t('risk.ours.preview')}</p>
            </div>
            <div className="flex flex-wrap items-baseline gap-2">
                <span className="t-admin-num-lg tabular text-fg-primary">{ours.internal_score}</span>
                <span className="t-admin-body text-fg-secondary">{t('risk.ours.of100')}</span>
                <RiskPill level={level} score={ours.internal_score} />
            </div>
            <Meter percent={ours.internal_score} tone={levelLook[level].bar} size="md" />
            {fired.length > 0 ? (
                <div className="flex flex-col gap-3">
                    <p className="t-admin-body-strong text-fg-primary">{t('risk.ours.raised')}</p>
                    <ul className="flex flex-col gap-3" aria-label={t('risk.ours.raised')}>
                        {fired.map((f) => (
                            <li key={f.code} className="grid grid-cols-[minmax(0,1fr)_--spacing(24)_--spacing(8)] items-center gap-3">
                                <span className="t-admin-body text-fg-primary">{factorSentence(f, t)}</span>
                                <Meter percent={f.weight > 0 ? (f.contribution / f.weight) * 100 : 0} tone="bg-feedback-danger" />
                                <span className="text-end t-admin-caption-strong tabular text-feedback-danger">+{f.contribution}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : (
                <p className="t-admin-body text-fg-secondary">{t('risk.ours.nothing')}</p>
            )}
            {quiet.length > 0 && (
                <p className="t-admin-caption text-fg-tertiary">
                    {t('risk.ours.quiet', { list: quiet.map((f) => t(`risk.factor.${f.code}` as MessageKey)).join(' · ') })}
                </p>
            )}
        </section>
    );
}

const statusIcon: Record<ListStatusValue, ReactNode> = {
    normal: <SealCheckIcon size={16} aria-hidden className="text-fg-secondary" />,
    watch: <EyeIcon size={16} aria-hidden className="text-status-low-stock" />,
    blacklist: <ProhibitIcon size={16} aria-hidden className="text-feedback-danger" />,
    whitelist: <SealCheckIcon size={16} aria-hidden className="text-feedback-success" />,
};
const statusFill: Record<ListStatusValue, string> = {
    normal: 'bg-surface border border-line-default',
    watch: 'bg-warning-subtle',
    blacklist: 'bg-danger-subtle',
    whitelist: 'bg-success-subtle',
};

/** The staff decision strip: status, the reason in their words, who and when — a third, separate block. */
export function ListStatusBanner({ status, phone, onChanged }: { status: ListStatus; phone: string | null; onChanged?: () => void }) {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const [open, setOpen] = useState(false);
    return (
        <section className={`flex flex-wrap items-center gap-4 rounded-md px-5 py-4 ${statusFill[status.value]}`} aria-label={t('risk.status.region')}>
            <span className="inline-flex items-center gap-1.5 rounded-sm border border-line-default bg-surface px-2.5 py-1 t-admin-body-strong text-fg-primary">
                {statusIcon[status.value]}
                {t(`risk.status.label.${status.value}` as MessageKey)}
            </span>
            <div className="flex min-w-0 flex-1 flex-col">
                <p className="t-admin-body text-fg-primary">{status.reason ? t('risk.status.quote', { reason: status.reason }) : t('risk.status.none')}</p>
                {status.set_at && (
                    <p className="t-admin-caption text-fg-secondary">
                        {t('risk.status.setBy', {
                            who: status.set_by ?? '—',
                            when: dhakaTime(status.set_at, locale),
                        })}
                    </p>
                )}
            </div>
            {phone && can(me, 'risk.manage_lists') && (
                <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
                    {t('risk.status.change')}
                </Button>
            )}
            {open && phone && <StatusDialog phone={phone} current={status.value} onClose={() => setOpen(false)} onSaved={onChanged} />}
        </section>
    );
}

/** Change a phone's list status: a reason always, the last 4 digits typed to blacklist. */
export function StatusDialog({ phone, current, preset, onClose, onSaved }: { phone: string; current: ListStatusValue; preset?: ListStatusValue; onClose: () => void; onSaved?: () => void }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useSetPhoneStatus();
    const [status, setStatus] = useState<ListStatusValue>(preset ?? current);
    const [reason, setReason] = useState('');
    const [confirm, setConfirm] = useState('');
    const [error, setError] = useState<string | null>(null);
    const submit = () =>
        save.mutate(
            {
                phone,
                status,
                reason,
                confirm: status === 'blacklist' ? confirm : null,
            },
            {
                onSuccess: () => {
                    notify(t('risk.status.saved'));
                    onSaved?.();
                    onClose();
                },
                onError: (e) => setError(e instanceof ApiError ? (e.problem.errors?.[0]?.message ?? e.problem.title) : t('risk.error')),
            },
        );
    return (
        <div role="dialog" aria-modal="true" aria-labelledby="risk-status-title" className="fixed inset-0 z-50 grid place-items-center bg-canvas/80 p-4">
            <form
                className="flex w-full max-w-130 flex-col gap-4 rounded-lg border border-line-default bg-surface p-5 shadow-e4"
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
            >
                <h2 id="risk-status-title" className="t-admin-section text-fg-primary">
                    {t('risk.status.title')}
                </h2>
                <p className="t-admin-body text-fg-secondary">{t('risk.status.body')}</p>
                <Field label={t('risk.status.status')}>
                    {({ id, describedBy, invalid }) => (
                        <Select id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={status} onChange={(e) => setStatus(e.target.value as ListStatusValue)}>
                            {(['normal', 'watch', 'blacklist', 'whitelist'] as const).map((s) => (
                                <option key={s} value={s}>
                                    {t(`risk.status.label.${s}` as MessageKey)}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>
                <Field label={t('risk.status.reason')} required>
                    {({ id, describedBy, invalid }) => (
                        <Input
                            id={id}
                            aria-describedby={describedBy}
                            aria-invalid={invalid || undefined}
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            required
                            minLength={3}
                            maxLength={500}
                        />
                    )}
                </Field>
                {status === 'blacklist' && (
                    <Field label={t('risk.status.confirm')} required>
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                aria-describedby={describedBy}
                                aria-invalid={invalid || undefined}
                                value={confirm}
                                onChange={(e) => setConfirm(e.target.value)}
                                inputMode="numeric"
                                maxLength={4}
                                className="tabular"
                            />
                        )}
                    </Field>
                )}
                {error && (
                    <p role="alert" className="t-admin-caption text-feedback-danger">
                        {error}
                    </p>
                )}
                <div className="flex justify-end gap-2 border-t border-line-default pt-4">
                    <Button type="button" variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button
                        type="submit"
                        variant={status === 'blacklist' ? 'danger' : 'primary'}
                        disabled={save.isPending || reason.trim().length < 3 || (status === 'blacklist' && confirm.length !== 4)}
                    >
                        {t('risk.status.save')}
                    </Button>
                </div>
            </form>
        </div>
    );
}
