import { useId, useState, type ComponentType, type FormEvent } from 'react';
import { Link } from 'react-router';
import {
    ArrowsClockwiseIcon,
    ChartLineUpIcon,
    CheckIcon,
    MegaphoneIcon,
    PackageIcon,
    PaperPlaneRightIcon,
    ShieldCheckIcon,
    ShoppingCartIcon,
    SparkleIcon,
    TagIcon,
    TruckIcon,
    WarningIcon,
    type IconProps,
} from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ApiError, PROBLEM } from '@/lib/api';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button, buttonClass } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { StatusChip, type StatusTone } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { AnalyticsTabs } from '@/features/analytics/AnalyticsTabs';
import { AiByline, AiErrorNotice, AiPanel, modelLabel } from '../AiNotice';
import { useAskAdvisor, useDecideAction, useGrowthAdvisor, useRefreshBrief, type AdvisorAction, type AdvisorAnswer, type AdvisorBrief, type AdvisorLink, type GrowthAdvisor } from './api';

const priorityTone: Record<AdvisorAction['priority'], StatusTone> = { high: 'danger', medium: 'warning', low: 'neutral' };

const linkIcon: Record<AdvisorLink['type'], ComponentType<IconProps>> = {
    product: PackageIcon,
    restock_forecast: ChartLineUpIcon,
    purchase_order_new: ShoppingCartIcon,
    low_stock: PackageIcon,
    verification_queue: ShieldCheckIcon,
    failed_bookings: TruckIcon,
    promotion: MegaphoneIcon,
    promotions: MegaphoneIcon,
    coupons: TagIcon,
    sales_report: ChartLineUpIcon,
};

/** "16 Nov" in the UI language, Western digits (rule 36). */
function formatDay(date: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC', numberingSystem: 'latn' }).format(new Date(`${date}T00:00:00Z`));
}

/** "16 Nov – 18 Nov" in the UI language, Western digits (rule 36); the dates are the server's Dhaka dates. */
function briefRange(brief: AdvisorBrief, locale: string): string {
    return brief.from === brief.to ? formatDay(brief.from, locale) : `${formatDay(brief.from, locale)} – ${formatDay(brief.to, locale)}`;
}

/** "Mon 16 Nov, 9:00 am" in Dhaka time (rule 11), Western digits. */
function writtenAt(iso: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
        timeZone: 'Asia/Dhaka',
        numberingSystem: 'latn',
    }).format(new Date(iso));
}

/** A notice for an AI problem the screen already knows about (no request needed). */
function knownProblem(slug: 'ai-not-configured'): ApiError {
    return new ApiError(409, { type: PROBLEM + slug, title: '', status: 409 });
}

/**
 * Analytics › Growth advisor (Figma 537:82561). The weekly brief and its suggestions come from
 * the server, already checked against the report figures (rule 1); links are server-built paths.
 * Nothing is changed until a person presses a button.
 */
export function GrowthAdvisorPage() {
    const { t } = useI18n();
    const advisor = useGrowthAdvisor();
    const refresh = useRefreshBrief();
    const data = advisor.data;
    const ready = data !== undefined && data.ai.configured && data.ai.figures_shared;

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('courierReport.crumb') }]}
                title={t('aiAdvisor.title')}
                subtitle={t('aiAdvisor.subtitle')}
                actions={
                    ready && (
                        <Button variant="outline" onClick={() => refresh.mutate()} disabled={refresh.isPending}>
                            <ArrowsClockwiseIcon size={16} aria-hidden />
                            {refresh.isPending ? t('aiAdvisor.refreshing') : t('aiAdvisor.refresh')}
                        </Button>
                    )
                }
            />
            <AnalyticsTabs />

            {advisor.isError ? (
                <QueryError error={advisor.error} onRetry={() => void advisor.refetch()} />
            ) : !data ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('aiAdvisor.loading')}
                </p>
            ) : (
                <div className="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
                    <div className="flex min-w-0 flex-col gap-6">
                        {refresh.isError && <RefreshError error={refresh.error} onRetry={() => refresh.mutate()} />}
                        <BriefColumn data={data} writing={refresh.isPending} onWrite={() => refresh.mutate()} />
                    </div>
                    {ready && <AskPanel provider={data.ai.provider} />}
                </div>
            )}
        </section>
    );
}

function RefreshError({ error, onRetry }: { error: unknown; onRetry: () => void }) {
    const { t } = useI18n();
    if (error instanceof ApiError && error.status === 429) {
        return (
            <p role="alert" className="flex items-start gap-2 rounded-sm bg-subtle px-3.5 py-2.5 t-admin-body text-fg-primary">
                <WarningIcon size={16} aria-hidden className="mt-0.5 shrink-0 text-feedback-warning" />
                {t('aiAdvisor.tooMany')}
            </p>
        );
    }
    return <AiErrorNotice error={error} onRetry={onRetry} />;
}

function BriefColumn({ data, writing, onWrite }: { data: GrowthAdvisor; writing: boolean; onWrite: () => void }) {
    const { t, locale } = useI18n();
    const brief = data.brief;

    if (!data.ai.figures_shared) {
        return (
            <div role="alert" className="flex flex-wrap items-start gap-3 rounded-md border border-line-default bg-subtle p-4">
                <WarningIcon size={20} aria-hidden className="mt-0.5 shrink-0 text-feedback-warning" />
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <p className="t-admin-body-strong text-fg-primary">{t('aiAdvisor.figuresOff.title')}</p>
                    <p className="t-admin-body text-fg-secondary">{t('aiAdvisor.figuresOff.body')}</p>
                </div>
                <Link to="/integrations/ai" className={buttonClass('outline', 'sm')}>
                    {t('ai.problem.openSettings')}
                </Link>
            </div>
        );
    }
    if (!data.ai.configured && brief === null) return <AiErrorNotice error={knownProblem('ai-not-configured')} />;
    if (brief === null) {
        return writing ? (
            <AiPanel title={t('aiAdvisor.title')}>
                <p className="t-admin-body text-fg-secondary" aria-busy="true">
                    {t('aiAdvisor.brief.writing')}
                </p>
            </AiPanel>
        ) : (
            <EmptyState
                title={t('aiAdvisor.empty.title')}
                body={t('aiAdvisor.empty.body')}
                actions={
                    data.ai.configured && (
                        <Button onClick={onWrite}>
                            <SparkleIcon size={16} aria-hidden />
                            {t('aiAdvisor.empty.action')}
                        </Button>
                    )
                }
            />
        );
    }

    const when = writtenAt(brief.generated_at, locale);
    return (
        <>
            {!data.ai.configured && <AiErrorNotice error={knownProblem('ai-not-configured')} />}
            <AiPanel title={t('aiAdvisor.brief.title', { range: briefRange(brief, locale) })}>
                <ul className="flex flex-col gap-2" aria-busy={writing}>
                    {brief.sentences.map((sentence, i) => (
                        <li key={i} className="flex items-start gap-2 t-admin-body">
                            <span aria-hidden className="shrink-0 text-fg-brand">
                                •
                            </span>
                            <span className="min-w-0 flex-1 text-fg-primary">{sentence}</span>
                        </li>
                    ))}
                </ul>
                <AiByline model={modelLabel(brief.model)}>{t(brief.scheduled ? 'aiAdvisor.brief.scheduledOn' : 'aiAdvisor.brief.writtenOn', { when })}</AiByline>
            </AiPanel>
            <Suggestions data={data} />
        </>
    );
}

function Suggestions({ data }: { data: GrowthAdvisor }) {
    const { t } = useI18n();
    return (
        <section className="flex flex-col gap-4" aria-labelledby="advisor-actions">
            <div className="flex flex-wrap items-center gap-2">
                <h2 id="advisor-actions" className="min-w-0 flex-1 t-admin-section text-fg-primary">
                    {t('aiAdvisor.actions.title')}
                </h2>
                <p className="t-admin-caption text-fg-secondary">{t('aiAdvisor.actions.count', { open: data.open_count, done: data.done_this_month })}</p>
            </div>
            {data.actions.length === 0 ? (
                <p className="t-admin-body text-fg-tertiary">{t('aiAdvisor.actions.empty')}</p>
            ) : (
                data.actions.map((action) => <ActionCard key={action.id} action={action} />)
            )}
        </section>
    );
}

function ActionCard({ action }: { action: AdvisorAction }) {
    const { t } = useI18n();
    const decide = useDecideAction();
    const toast = useToast();
    const Icon = action.link ? linkIcon[action.link.type] : null;
    const run = (decision: 'done' | 'dismiss') =>
        decide.mutate(
            { id: action.id, decision },
            {
                onSuccess: () => toast.notify(t(decision === 'done' ? 'aiAdvisor.toast.done' : 'aiAdvisor.toast.dismissed')),
                onError: () => toast.notify(t('ai.problem.other.title'), 'danger'),
            },
        );

    return (
        <article className="flex flex-col gap-3 rounded-md border border-line-default bg-surface p-5 shadow-e1">
            <div className="flex items-center gap-2">
                <StatusChip label={t(`aiAdvisor.priority.${action.priority}` as MessageKey)} tone={priorityTone[action.priority]} />
                <h3 className="min-w-0 flex-1 t-admin-body-strong text-fg-primary">{action.title}</h3>
            </div>
            <p className="t-admin-body text-fg-secondary">{action.why}</p>
            {action.figures.length > 0 && (
                <ul className="flex flex-wrap gap-2">
                    {action.figures.map((figure, i) => (
                        <li key={i} className="rounded-xs bg-subtle px-2 py-0.5 t-admin-caption-strong text-fg-primary tabular">
                            {figure}
                        </li>
                    ))}
                </ul>
            )}
            <div className="flex flex-wrap items-center gap-2">
                {action.link && Icon && (
                    <Link to={action.link.path} className={buttonClass('primary', 'sm')}>
                        <Icon size={16} aria-hidden />
                        {t(`aiAdvisor.cta.${action.link.type}` as MessageKey)}
                    </Link>
                )}
                <Button variant="ghost" size="sm" onClick={() => run('done')} disabled={decide.isPending}>
                    <CheckIcon size={16} aria-hidden />
                    {t('aiAdvisor.markDone')}
                </Button>
                <Button variant="ghost" size="sm" onClick={() => run('dismiss')} disabled={decide.isPending}>
                    {t('aiAdvisor.notUseful')}
                </Button>
            </div>
        </article>
    );
}

type Exchange = { id: number; question: string; answer?: AdvisorAnswer; error?: unknown };

function AskPanel({ provider }: { provider: GrowthAdvisor['ai']['provider'] }) {
    const { t } = useI18n();
    const ids = useId();
    const ask = useAskAdvisor();
    const [question, setQuestion] = useState('');
    const [exchanges, setExchanges] = useState<Exchange[]>([]);

    const send = (text: string) => {
        const q = text.trim();
        if (q.length < 3 || ask.isPending) return;
        const id = Date.now();
        setExchanges((list) => [...list, { id, question: q }]);
        setQuestion('');
        ask.mutate(
            { question: q },
            {
                onSuccess: (answer) => setExchanges((list) => list.map((e) => (e.id === id ? { ...e, answer } : e))),
                onError: (error) => setExchanges((list) => list.map((e) => (e.id === id ? { ...e, error } : e))),
            },
        );
    };
    const onSubmit = (e: FormEvent) => {
        e.preventDefault();
        send(question);
    };

    return (
        <section className="flex flex-col gap-4 rounded-md border border-line-default bg-surface p-5 shadow-e1" aria-labelledby={`${ids}-title`}>
            <div className="flex items-center gap-2">
                <SparkleIcon size={20} aria-hidden className="text-fg-brand" />
                <h2 id={`${ids}-title`} className="min-w-0 flex-1 t-admin-section text-fg-primary">
                    {t('aiAdvisor.ask.title')}
                </h2>
                <StatusChip label={t(`aiAdvisor.provider.${provider}` as MessageKey)} tone="brand" />
            </div>

            <div className="flex flex-col gap-4" aria-live="polite">
                {exchanges.map((exchange) => (
                    <div key={exchange.id} className="flex flex-col gap-3">
                        <div className="flex justify-end">
                            <p className="max-w-[85%] rounded-md bg-brand px-3 py-2 t-admin-body text-fg-on-brand">{exchange.question}</p>
                        </div>
                        {exchange.answer ? (
                            <AnswerCard answer={exchange.answer} />
                        ) : exchange.error ? (
                            exchange.error instanceof ApiError && exchange.error.status === 429 ? (
                                <p role="alert" className="rounded-md bg-subtle p-4 t-admin-body text-fg-primary">
                                    {t('aiAdvisor.tooMany')}
                                </p>
                            ) : (
                                <AiErrorNotice error={exchange.error} onRetry={() => send(exchange.question)} />
                            )
                        ) : (
                            <p className="rounded-md bg-subtle p-4 t-admin-body text-fg-secondary" aria-busy="true">
                                {t('aiAdvisor.ask.thinking')}
                            </p>
                        )}
                    </div>
                ))}
            </div>

            <div className="flex flex-col items-start gap-2">
                <p className="t-admin-caption text-fg-tertiary">{t('aiAdvisor.ask.try')}</p>
                {(['aiAdvisor.ask.s1', 'aiAdvisor.ask.s2', 'aiAdvisor.ask.s3'] as const).map((key) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => send(t(key))}
                        disabled={ask.isPending}
                        className="rounded-full border border-line-default px-3 py-2 text-left t-admin-caption-strong text-fg-primary hover:bg-subtle disabled:text-fg-disabled"
                    >
                        {t(key)}
                    </button>
                ))}
            </div>

            <form onSubmit={onSubmit} className="flex items-center gap-2">
                <label htmlFor={`${ids}-q`} className="sr-only">
                    {t('aiAdvisor.ask.label')}
                </label>
                <Input id={`${ids}-q`} value={question} maxLength={500} onChange={(e) => setQuestion(e.target.value)} placeholder={t('aiAdvisor.ask.placeholder')} className="min-w-0 flex-1" />
                <Button type="submit" size="md" aria-label={t('aiAdvisor.ask.send')} disabled={ask.isPending || question.trim().length < 3} className="w-9 px-0">
                    <PaperPlaneRightIcon size={18} aria-hidden />
                </Button>
            </form>
            <p className="t-admin-caption text-fg-tertiary">{t('aiAdvisor.ask.footnote')}</p>
        </section>
    );
}

function AnswerCard({ answer }: { answer: AdvisorAnswer }) {
    const { t } = useI18n();
    return (
        <div className="flex flex-col gap-2 rounded-md bg-subtle p-4">
            <ul className="flex flex-col gap-2">
                {answer.answer_sentences.map((sentence, i) => (
                    <li key={i} className="flex items-start gap-2 t-admin-body text-fg-primary">
                        <span aria-hidden className="shrink-0">
                            •
                        </span>
                        <span className="min-w-0 flex-1">{sentence}</span>
                    </li>
                ))}
            </ul>
            {answer.trimmed && <p className="t-admin-caption text-fg-secondary">{t('aiAdvisor.ask.trimmed')}</p>}
            {answer.sources.length > 0 && (
                <ul className="flex flex-wrap gap-2">
                    {answer.sources.map((source, i) => (
                        <li key={i} className="rounded-xs bg-surface px-2 py-0.5 t-admin-caption-strong text-fg-primary">
                            {t(`aiAdvisor.source.${source.section}` as MessageKey)}
                            {source.detail ? ` · ${source.detail}` : ''}
                        </li>
                    ))}
                </ul>
            )}
            <AiByline model={modelLabel(answer.model)} />
        </div>
    );
}
