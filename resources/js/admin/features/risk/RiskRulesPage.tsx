import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import { CheckIcon, ClockIcon, InfoIcon, ListChecksIcon, ShieldCheckIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button, buttonClass } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { ApiError } from '@/lib/api';
import { TAKA } from '@/lib/money';
import { factorCodes, useRiskRules, useSaveRules, type RiskRules, type RulesInput } from './api';
import { RiskTabs, cardClass, crumbs } from './parts';

type Draft = Record<string, string>;
type FactorCode = (typeof factorCodes)[number];

function draftOf(rules: RiskRules): Draft {
    const next: Draft = {
        medium_threshold: String(rules.medium_threshold),
        high_threshold: String(rules.high_threshold),
        first_order_hold_threshold: rules.first_order_hold_threshold,
        value_norm_multiplier: rules.value_norm_multiplier,
        rapid_repeat_minutes: String(rules.rapid_repeat_minutes),
    };
    for (const code of factorCodes) next[`w.${code}`] = String(rules.weights[code] ?? 0);
    return next;
}

/** A setting that lives inside a factor's sentence ("…at least [2] × their usual order"). */
const inlineParam: Partial<Record<FactorCode, { key: 'value_norm_multiplier' | 'rapid_repeat_minutes'; inputMode: 'decimal' | 'numeric' }>> = {
    value_above_norm: { key: 'value_norm_multiplier', inputMode: 'decimal' },
    rapid_repeat: { key: 'rapid_repeat_minutes', inputMode: 'numeric' },
};

/**
 * Risk rules (Figma B · 40 premium 504:81358, 390: 504:82192). Asked as questions: when to pause
 * an order, when to always check a first order, and what raises the score — each factor in plain
 * words with its points, the two factor settings inside their sentence. The server validates
 * (points total ≤ 100, medium < high) and scores only new orders with the new values (rule 1).
 */
export function RiskRulesPage() {
    const { t } = useI18n();
    const { notify } = useToast();
    const rules = useRiskRules();
    const save = useSaveRules();
    const [draft, setDraft] = useState<Draft>({});
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (rules.data) setDraft(draftOf(rules.data));
    }, [rules.data]);

    if (rules.isError) return <QueryError error={rules.error} onRetry={() => void rules.refetch()} />;
    const editable = rules.data?.can_edit === true;
    const value = (key: string) => draft[key] ?? '';
    const set = (key: string) => (e: React.ChangeEvent<HTMLInputElement>) => setDraft((d) => ({ ...d, [key]: e.target.value }));
    const err = (key: string) => errors[key];
    // Display-only: what the owner is typing. The server checks the total again on save.
    const used = factorCodes.reduce((sum, c) => sum + (Number(value(`w.${c}`)) || 0), 0);
    const medium = Number(value('medium_threshold')) || 0;
    const high = Number(value('high_threshold')) || 0;
    const dirty = rules.data !== undefined && JSON.stringify(draftOf(rules.data)) !== JSON.stringify(draft);

    const submit = () => {
        const input: RulesInput = {
            weights: Object.fromEntries(factorCodes.map((c) => [c, Number(value(`w.${c}`))])) as RulesInput['weights'],
            medium_threshold: Number(value('medium_threshold')),
            high_threshold: Number(value('high_threshold')),
            first_order_hold_threshold: value('first_order_hold_threshold'),
            value_norm_multiplier: Number(value('value_norm_multiplier')),
            rapid_repeat_minutes: Number(value('rapid_repeat_minutes')),
        };
        save.mutate(input, {
            onSuccess: () => (setErrors({}), notify(t('risk.rules.saved'))),
            onError: (e) => {
                if (e instanceof ApiError && e.problem.errors) setErrors(Object.fromEntries(e.problem.errors.map((x) => [x.field, x.message])));
                else notify(t('risk.error'), 'danger');
            },
        });
    };

    const numberInput = (key: string, label: string, opts: { width?: string; inputMode?: 'decimal' | 'numeric'; prefix?: string } = {}) => (
        <Input
            prefix={opts.prefix}
            aria-label={label}
            aria-invalid={err(key) ? true : undefined}
            inputMode={opts.inputMode ?? 'numeric'}
            value={value(key)}
            onChange={set(key)}
            disabled={!editable}
            className={`tabular ${opts.width ?? 'w-20'}`}
        />
    );
    const fieldError = (key: string) =>
        err(key) && (
            <p role="alert" className="t-admin-caption text-feedback-danger">
                {err(key)}
            </p>
        );

    return (
        <div className="flex flex-col gap-6 pb-24 sm:pb-0">
            <ContentHeader
                title={t('risk.rules.title')}
                breadcrumb={crumbs(t, 'risk.tab.rules')}
                subtitle={t('risk.rules.subtitle')}
                actions={
                    <Link to="/risk/queue" className={buttonClass('outline', 'md')}>
                        <ListChecksIcon size={16} aria-hidden />
                        {t('risk.tab.queue')}
                    </Link>
                }
            />
            <RiskTabs />
            <p className="flex items-center gap-3 rounded-md bg-subtle px-4 py-3 t-admin-body text-fg-secondary">
                <ShieldCheckIcon size={18} aria-hidden className="shrink-0 text-feedback-success" />
                <span>
                    {t('risk.rules.provisional')} {!editable && rules.data ? t('risk.rules.readOnly') : ''}
                </span>
            </p>
            <form
                className="flex flex-col gap-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
            >
                {/* 1 · When to pause */}
                <section className={cardClass} aria-labelledby="rules-pause">
                    <div className="flex flex-col gap-1">
                        <h2 id="rules-pause" className="t-admin-body-strong text-fg-primary">
                            {t('risk.rules.pause.title')}
                        </h2>
                        <p className="t-admin-body text-fg-secondary">{t('risk.rules.pause.body')}</p>
                    </div>
                    <div className="flex flex-col gap-2">
                        <span aria-hidden className="flex h-3 w-full gap-0.5 overflow-hidden rounded-full">
                            <span className="h-full bg-feedback-success" style={{ flexGrow: Math.max(medium, 1) }} />
                            <span className="h-full bg-feedback-warning" style={{ flexGrow: Math.max(high - medium, 1) }} />
                            <span className="h-full bg-feedback-danger" style={{ flexGrow: Math.max(101 - high, 1) }} />
                        </span>
                        <div className="hidden gap-0.5 sm:flex">
                            {(
                                [
                                    ['risk.rules.zone.ships', `0 – ${Math.max(medium - 1, 0)}`, Math.max(medium, 1)],
                                    ['risk.rules.zone.look', `${medium} – ${Math.max(high - 1, medium)}`, Math.max(high - medium, 1)],
                                    ['risk.rules.zone.pause', `${high} – 100`, Math.max(101 - high, 1)],
                                ] as [MessageKey, string, number][]
                            ).map(([label, range, grow]) => (
                                <span key={label} className="flex min-w-0 flex-col" style={{ flexGrow: grow, flexBasis: 0 }}>
                                    <span className="t-admin-body-strong text-fg-primary">{t(label)}</span>
                                    <span className="t-admin-caption tabular text-fg-secondary">{range}</span>
                                </span>
                            ))}
                        </div>
                    </div>
                    <div className="grid gap-5 sm:grid-cols-2">
                        {(
                            [
                                ['medium_threshold', 'risk.rules.medium', 'risk.rules.mediumHint'],
                                ['high_threshold', 'risk.rules.high', 'risk.rules.highHint'],
                            ] as [string, MessageKey, MessageKey][]
                        ).map(([key, label, hint]) => (
                            <div key={key} className="flex flex-col gap-1.5">
                                <p className="t-admin-caption-strong text-fg-primary">{t(label)}</p>
                                <span className="flex items-center gap-2">
                                    {numberInput(key, t(label))}
                                    <span className="t-admin-body text-fg-secondary">{t('risk.rules.pointsOrMore')}</span>
                                </span>
                                <p className="t-admin-caption text-fg-tertiary">{t(hint)}</p>
                                {fieldError(key)}
                            </div>
                        ))}
                    </div>
                    <p className="flex items-start gap-2 rounded-sm bg-subtle px-3 py-2 t-admin-caption tabular text-fg-secondary">
                        <InfoIcon size={16} aria-hidden className="mt-px shrink-0 text-feedback-info" />
                        {t('risk.rules.example', { high, medium })}
                    </p>
                </section>

                {/* 2 · First orders */}
                <section className={`${cardClass} sm:flex-row sm:items-center`} aria-labelledby="rules-first">
                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                        <h2 id="rules-first" className="t-admin-body-strong text-fg-primary">
                            {t('risk.rules.firstOrder')}
                        </h2>
                        <p className="t-admin-body text-fg-secondary">{t('risk.rules.firstOrderHint')}</p>
                        {fieldError('first_order_hold_threshold')}
                    </div>
                    {numberInput('first_order_hold_threshold', t('risk.rules.firstOrder'), { width: 'w-40', inputMode: 'decimal', prefix: TAKA })}
                </section>

                {/* 3 · What raises the score */}
                <section className="flex flex-col overflow-hidden rounded-md border border-line-default bg-surface shadow-e1" aria-labelledby="rules-factors">
                    <div className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
                        <div className="flex min-w-0 flex-1 flex-col gap-1">
                            <h2 id="rules-factors" className="t-admin-body-strong text-fg-primary">
                                {t('risk.rules.weights')}
                            </h2>
                            <p className="t-admin-body text-fg-secondary">{t('risk.rules.weightsHint')}</p>
                            {fieldError('weights')}
                        </div>
                        <div className="flex flex-col gap-1.5 rounded-md bg-subtle p-4 sm:w-56" aria-live="polite">
                            <p className="flex items-baseline gap-1.5">
                                <span className={`t-admin-num-lg tabular ${used > 100 ? 'text-feedback-danger' : 'text-fg-primary'}`}>{used}</span>
                                <span className="t-admin-body text-fg-secondary">{t('risk.rules.used')}</span>
                            </p>
                            <span aria-hidden className="block h-1.5 w-full overflow-hidden rounded-full bg-surface">
                                <span className={`block h-full rounded-full ${used > 100 ? 'bg-feedback-danger' : 'bg-brand'}`} style={{ width: `${Math.min(used, 100)}%` }} />
                            </span>
                            {used > 100 && <p className="t-admin-caption text-feedback-danger">{t('risk.rules.overBudget')}</p>}
                        </div>
                    </div>
                    <div className="hidden items-center gap-4 border-t border-line-default bg-subtle px-5 py-3 sm:flex">
                        <span className="flex-1 t-admin-overline text-fg-secondary">{t('risk.rules.col.sign')}</span>
                        <span className="w-40 t-admin-overline text-fg-secondary">{t('risk.rules.col.points')}</span>
                    </div>
                    <ul>
                        {factorCodes.map((code) => {
                            const param = inlineParam[code];
                            return (
                                <li key={code} className="flex items-center gap-4 border-t border-line-default px-5 py-4">
                                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                                        <p className="t-admin-body-strong text-fg-primary">{t(`risk.rules.factor.${code}` as MessageKey)}</p>
                                        {param ? (
                                            <p className="flex flex-wrap items-center gap-2 t-admin-body text-fg-secondary">
                                                <span>{t(`risk.rules.factorBefore.${code}` as MessageKey)}</span>
                                                {numberInput(param.key, t(`risk.rules.factorParam.${code}` as MessageKey), { width: 'w-16', inputMode: param.inputMode })}
                                                <span>{t(`risk.rules.factorAfter.${code}` as MessageKey)}</span>
                                            </p>
                                        ) : (
                                            <p className="t-admin-body text-fg-secondary">{t(`risk.rules.factorHint.${code}` as MessageKey)}</p>
                                        )}
                                        {param && fieldError(param.key)}
                                        {fieldError(`weights.${code}`)}
                                    </div>
                                    <span className="flex items-center gap-2 sm:w-40">
                                        {numberInput(`w.${code}`, t('risk.rules.pointsFor', { factor: t(`risk.rules.factor.${code}` as MessageKey) }), { width: 'w-18' })}
                                        <span className="hidden t-admin-body text-fg-secondary sm:inline">{t('risk.rules.points')}</span>
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                </section>

                {editable && (
                    <div className="fixed inset-x-0 bottom-0 z-30 flex flex-col gap-2 border-t border-line-default bg-surface p-4 sm:static sm:flex-row sm:items-center sm:gap-3 sm:rounded-md sm:border sm:px-6 sm:shadow-e1">
                        <p className="flex min-w-0 flex-1 items-center gap-2 t-admin-body text-fg-secondary">
                            <ClockIcon size={16} aria-hidden className="shrink-0 text-fg-tertiary" />
                            {t('risk.rules.newOnly')}
                        </p>
                        <span className="flex gap-2">
                            <Button type="button" variant="outline" className="flex-1 sm:flex-none" disabled={!dirty || save.isPending} onClick={() => rules.data && (setDraft(draftOf(rules.data)), setErrors({}))}>
                                {t('risk.rules.discard')}
                            </Button>
                            <Button type="submit" className="flex-1 sm:flex-none" disabled={save.isPending}>
                                <CheckIcon size={16} aria-hidden />
                                {t('risk.rules.save')}
                            </Button>
                        </span>
                    </div>
                )}
            </form>
        </div>
    );
}
