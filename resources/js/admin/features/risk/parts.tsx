import { useState, type ReactNode } from 'react';
import { Link } from 'react-router';
import { CpuIcon, EyeIcon, PlugsIcon, ProhibitIcon, SealCheckIcon, ShieldCheckIcon, ShieldWarningIcon, WifiSlashIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { TabLink, TabList } from '@/components/ui/Tabs';
import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { ApiError } from '@/lib/api';
import { factorCodes, useSetPhoneStatus, type CourierRecord, type ListStatus, type ListStatusValue, type OurScore, type RiskFactorRow, type RiskLevel } from './api';

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

/** "6260.00" → "৳6,260": groups the server's decimal string; never computes (rule 1). */
export function taka(decimal: string): string {
    const negative = decimal.startsWith('-');
    const [whole = '', fraction = ''] = (negative ? decimal.slice(1) : decimal).split('.');
    const trimmed = fraction.replace(/0+$/, '');
    return `${negative ? '−' : ''}৳${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}${trimmed ? `.${trimmed}` : ''}`;
}

export function asLevel(level: string | null | undefined): RiskLevel {
    return level === 'high' || level === 'medium' ? level : 'low';
}

const levelLook: Record<RiskLevel, { edge: string; icon: string }> = {
    low: { edge: 'border-l-feedback-success', icon: 'text-feedback-success' },
    medium: {
        edge: 'border-l-status-low-stock',
        icon: 'text-status-low-stock',
    },
    high: { edge: 'border-l-feedback-danger', icon: 'text-feedback-danger' },
};

/** Figma RiskPill 190:405: shield + level word + number; the colour only reinforces. */
export function RiskPill({ level, score }: { level: RiskLevel; score: number }) {
    const { t } = useI18n();
    const look = levelLook[level];
    return (
        <span className={`inline-flex h-6 shrink-0 items-center gap-1 rounded-sm border border-line-default border-l-2 ${look.edge} bg-subtle px-2 t-admin-caption-strong text-fg-primary`}>
            <ShieldWarningIcon size={14} aria-hidden className={look.icon} />
            {t(`risk.level.${level}` as MessageKey, { score })}
        </span>
    );
}

/** The section's tab strip: queue · lookup · rules. */
export function RiskTabs() {
    const { t } = useI18n();
    return (
        <TabList label={t('risk.tabs')}>
            <TabLink to="/risk/queue" label={t('risk.tab.queue')} />
            <TabLink to="/risk/lookup" label={t('risk.tab.lookup')} />
            <TabLink to="/risk/rules" label={t('risk.tab.rules')} />
        </TabList>
    );
}

export const crumbs = (t: (k: MessageKey) => string, page: MessageKey) => [{ label: t('risk.crumb.deliver') }, { label: t('risk.crumb.section'), to: '/risk/queue' }, { label: t(page) }];

const card = 'flex flex-col gap-4 rounded-md border border-line-default bg-surface p-5 shadow-e1';

/** The 220° gauge from RiskScoreCard 197:858 — the number and the word carry the meaning. */
function Gauge({ score, level }: { score: number; level: RiskLevel }) {
    const { t } = useI18n();
    const r = 44;
    const length = (Math.PI * 2 * r * 220) / 360;
    const stroke = {
        low: 'stroke-feedback-success',
        medium: 'stroke-status-low-stock',
        high: 'stroke-feedback-danger',
    }[level];
    return (
        <div className="relative size-24 shrink-0">
            <svg viewBox="0 0 96 96" className="size-24 rotate-[160deg]" aria-hidden>
                <circle cx="48" cy="48" r={r} fill="none" strokeWidth="8" strokeLinecap="round" className="stroke-subtle" strokeDasharray={`${length} 999`} />
                <circle cx="48" cy="48" r={r} fill="none" strokeWidth="8" strokeLinecap="round" className={stroke} strokeDasharray={`${(length * Math.min(100, Math.max(0, score))) / 100} 999`} />
            </svg>
            <p className="absolute inset-0 flex flex-col items-center justify-center">
                <span className="t-admin-num-lg tabular text-fg-primary">{score}</span>
                <span className="t-admin-caption text-fg-tertiary">{t('risk.ours.of100')}</span>
            </p>
        </div>
    );
}

function factorLabel(code: string, t: (k: MessageKey) => string): string {
    return (factorCodes as readonly string[]).includes(code) ? t(`risk.factor.${code}` as MessageKey) : code;
}

/**
 * Panel A of RiskScoreCard (Figma 197:858; B · 06): OUR score, its breakdown — each factor
 * with its weight and contribution, the server's numbers — and when we computed it.
 * Never shows a courier figure (rule 24).
 */
export function OurScoreCard({ ours, title, provenance }: { ours: OurScore; title?: string; provenance?: string }) {
    const { t, locale } = useI18n();
    const level = asLevel(ours.level);
    const when = ours.score_computed_at ? dhakaTime(ours.score_computed_at, locale) : '—';
    return (
        <section className={`${card} border-t-3 border-t-brand`} aria-labelledby="risk-ours">
            <div className="flex flex-col gap-1">
                <p className="t-admin-overline text-fg-secondary">{t('risk.ours.eyebrow')}</p>
                <h2 id="risk-ours" className="t-admin-section text-fg-primary">
                    {title ?? t('risk.ours.title')}
                </h2>
                <p className="flex items-center gap-1.5 t-admin-caption text-fg-tertiary">
                    <CpuIcon size={14} aria-hidden />
                    {provenance ?? (ours.stored ? t('risk.ours.provenance', { when }) : t('risk.ours.preview'))}
                </p>
            </div>
            <div className="flex items-center gap-4">
                <Gauge score={ours.internal_score} level={level} />
                <div className="flex min-w-0 flex-col items-start gap-2">
                    <span
                        className={`inline-flex items-center gap-1.5 rounded-sm border border-line-default border-l-2 ${levelLook[level].edge} bg-subtle px-2.5 py-1 t-admin-body-strong text-fg-primary`}
                    >
                        <ShieldWarningIcon size={16} aria-hidden className={levelLook[level].icon} />
                        {t('risk.ours.chip', {
                            level: t(`risk.levelName.${level}` as MessageKey),
                            score: ours.internal_score,
                        })}
                    </span>
                    <p className="t-admin-caption text-fg-secondary">{t('risk.ours.policy')}</p>
                </div>
            </div>
            <Breakdown factors={ours.breakdown} score={ours.internal_score} />
            <p className="t-admin-caption text-fg-tertiary">{t('risk.ours.footnote')}</p>
        </section>
    );
}

export function Breakdown({ factors, score }: { factors: Partial<RiskFactorRow>[]; score: number }) {
    const { t } = useI18n();
    return (
        <ul className="flex flex-col" aria-label={t('risk.ours.title')}>
            {factors.map((f) => {
                const contribution = f.contribution ?? f.weight ?? 0;
                const weight = f.weight ?? contribution;
                const silent = contribution === 0;
                return (
                    <li key={f.code} className="flex items-center justify-between gap-3 border-t border-line-default py-2.5">
                        <span className="flex min-w-0 flex-col">
                            <span className={`t-admin-body ${silent ? 'text-fg-secondary' : 'text-fg-primary'}`}>{factorLabel(f.code ?? '', t)}</span>
                            <span className="t-admin-caption tabular text-fg-tertiary">{silent ? t('risk.ours.noSignal') : f.value}</span>
                        </span>
                        <span className="flex items-center gap-3">
                            <span aria-hidden className="h-1 w-20 overflow-hidden rounded-full bg-subtle">
                                <span
                                    className="block h-full rounded-full bg-line-strong"
                                    style={{
                                        width: `${weight > 0 ? (contribution / weight) * 100 : 0}%`,
                                    }}
                                />
                            </span>
                            <span className="w-8 text-end t-admin-num tabular text-fg-primary">{contribution}</span>
                        </span>
                    </li>
                );
            })}
            <li className="flex items-center justify-between gap-3 border-t border-line-strong py-2.5">
                <span className="t-admin-body-strong text-fg-primary">{t('risk.ours.total')}</span>
                <span className="t-admin-num tabular text-fg-primary">{t('risk.ours.outOf', { score })}</span>
            </li>
        </ul>
    );
}

/** Our history with the phone — counts of OUR orders, labelled as ours. */
export function OurCounts({ ours }: { ours: OurScore }) {
    const { t } = useI18n();
    const rows: [MessageKey, number][] = [
        ['risk.counts.orders', ours.counts.total_orders],
        ['risk.counts.delivered', ours.counts.delivered],
        ['risk.counts.cancelled', ours.counts.cancelled],
        ['risk.counts.returned', ours.counts.returned],
        ['risk.counts.refused', ours.counts.refused],
        ['risk.counts.failed', ours.counts.failed_delivery],
    ];
    return (
        <div className="flex flex-col gap-2">
            <p className="t-admin-overline text-fg-secondary">{t('risk.counts.title')}</p>
            <dl className="grid grid-cols-3 gap-2">
                {rows.map(([key, value]) => (
                    <div key={key} className="rounded-sm bg-subtle px-2.5 py-2">
                        <dt className="t-admin-caption text-fg-secondary">{t(key)}</dt>
                        <dd className="t-admin-num tabular text-fg-primary">{value}</dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}

const knownReasons = ['no_merchant_account', 'provider_unverified', 'no_official_api', 'driver_missing', 'provider_error'];

/**
 * Panel B of RiskScoreCard: each courier's OFFICIAL record, timestamped — or, when not
 * connected, an explicit "Provider not connected" with the reason. Never a zero (rule 33b),
 * never averaged with our score (rule 24).
 */
export function CourierPanel({ checkedAt, providers }: { checkedAt: string; providers: CourierRecord[] }) {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const connected = providers.filter((p) => p.connected);
    const missing = providers.filter((p) => !p.connected);
    const name = (p: CourierRecord) => (locale === 'bn' && p.name_bn ? p.name_bn : p.name);
    return (
        <section className="flex flex-col gap-4 rounded-md border border-dashed border-line-strong bg-surface p-5" aria-labelledby="risk-courier">
            <div className="flex flex-col gap-1">
                <p className="t-admin-overline text-fg-secondary">{t('risk.courier.eyebrow')}</p>
                <h2 id="risk-courier" className="t-admin-section text-fg-primary">
                    {t('risk.courier.title')}
                </h2>
                <p className="flex items-center gap-1.5 t-admin-caption text-fg-tertiary">
                    <ShieldCheckIcon size={14} aria-hidden />
                    {t('risk.courier.checked', {
                        when: dhakaTime(checkedAt, locale),
                    })}
                </p>
            </div>
            {connected.map((p) => (
                <div key={p.provider} className="flex flex-col gap-2 rounded-sm border border-line-default p-3">
                    <p className="t-admin-caption text-fg-secondary">
                        {t('risk.courier.fetched', {
                            name: name(p),
                            when: p.fetched_at ? dhakaTime(p.fetched_at, locale) : '—',
                        })}
                    </p>
                    <dl className="grid grid-cols-4 gap-2">
                        {(
                            [
                                ['risk.courier.parcels', p.total_parcels],
                                ['risk.courier.delivered', p.total_delivered],
                                ['risk.courier.cancelled', p.total_cancelled],
                                ['risk.courier.fraud', p.total_fraud_reports],
                            ] as [MessageKey, number | null][]
                        ).map(([key, value]) => (
                            <div key={key}>
                                <dt className="t-admin-caption text-fg-secondary">{t(key)}</dt>
                                <dd className="t-admin-num tabular text-fg-primary">{value ?? '—'}</dd>
                            </div>
                        ))}
                    </dl>
                </div>
            ))}
            {missing.length > 0 && (
                <div className="flex flex-col items-center gap-2 rounded-md bg-subtle px-6 py-6 text-center">
                    <WifiSlashIcon size={28} aria-hidden className="text-fg-secondary" />
                    <p className="t-admin-section text-fg-primary">{t('risk.courier.notConnected')}</p>
                    <p className="t-admin-body text-fg-secondary">{t('risk.courier.notConnectedBody')}</p>
                    <ul className="flex flex-col gap-1">
                        {missing.map((p) => (
                            <li key={p.provider} className="t-admin-caption text-fg-secondary">
                                {t(`risk.courier.reason.${knownReasons.includes(p.reason ?? '') ? p.reason : 'other'}` as MessageKey, { name: name(p) })}
                            </li>
                        ))}
                    </ul>
                    {can(me, 'settings.manage_integrations') && (
                        <Link
                            to="/integrations/couriers"
                            className="mt-1 inline-flex h-8 items-center gap-1.5 rounded-sm border border-line-default bg-surface px-3 t-admin-body-strong text-fg-primary hover:bg-subtle"
                        >
                            <PlugsIcon size={16} aria-hidden />
                            {t('risk.courier.settings')}
                        </Link>
                    )}
                </div>
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

/** The staff decision strip (B · 06): status, reason, who and when — a third, separate block. */
export function ListStatusBanner({ status, phone, onChanged }: { status: ListStatus; phone: string | null; onChanged?: () => void }) {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const [open, setOpen] = useState(false);
    return (
        <section className="flex flex-wrap items-center gap-4 rounded-md border border-line-default bg-surface px-4 py-3 shadow-e1">
            <span className="inline-flex items-center gap-1.5 rounded-sm border border-line-default bg-subtle px-2.5 py-1 t-admin-body-strong text-fg-primary">
                {statusIcon[status.value]}
                {t(`risk.status.label.${status.value}` as MessageKey)}
            </span>
            <div className="flex min-w-0 flex-1 flex-col">
                <p className="t-admin-body text-fg-primary">{status.reason ?? t('risk.status.none')}</p>
                {status.set_at && (
                    <p className="t-admin-caption text-fg-tertiary">
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
