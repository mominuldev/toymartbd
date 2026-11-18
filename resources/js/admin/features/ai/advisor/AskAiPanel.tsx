import { useEffect, useId, useRef, useState, type FormEvent, type KeyboardEvent } from 'react';
import { Link } from 'react-router';
import { NotePencilIcon, PaperPlaneRightIcon, SparkleIcon, WarningIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ApiError, PROBLEM } from '@/lib/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { inputClass } from '@/components/ui/Field';
import { IconButton } from '@/components/ui/IconButton';
import { QueryError } from '@/components/ui/QueryError';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { StatusChip } from '@/components/ui/StatusChip';
import { AiErrorNotice, modelLabel } from '../AiNotice';
import { useAskAdvisor, useGrowthAdvisor, type AdvisorAnswer } from './api';

type Lang = 'en' | 'bn';
type Exchange = { id: number; question: string; answer?: AdvisorAnswer; error?: unknown };

const suggestions = [
    { chip: 'askAi.c1', question: 'askAi.q1' },
    { chip: 'askAi.c2', question: 'askAi.q2' },
    { chip: 'askAi.c3', question: 'askAi.q3' },
] as const;

type AskAiPanelProps = {
    /** The panel stays mounted while closed (the conversation survives); focus moves in when it opens. */
    open: boolean;
    onClose: () => void;
};

/**
 * Ask AI panel (Figma B · 42 `603:51917`, section "08 · Inbox & AI — Premium pass"). A 440-wide,
 * full-height column the Shell opens beside any page. Answers come from
 * `POST /analytics/advisor/ask` — report figures only, checked by FigureGuard on the server
 * (rule 1) — so the panel only shows what the server wrote. Suggest only: nothing here changes data.
 */
export function AskAiPanel({ open, onClose }: AskAiPanelProps) {
    const { t, locale } = useI18n();
    const ids = useId();
    const advisor = useGrowthAdvisor();
    const ask = useAskAdvisor();
    const [answerIn, setAnswerIn] = useState<Lang>(locale === 'bn' ? 'bn' : 'en');
    const [question, setQuestion] = useState('');
    const [exchanges, setExchanges] = useState<Exchange[]>([]);
    const nextId = useRef(1);
    const box = useRef<HTMLTextAreaElement>(null);
    const closeButton = useRef<HTMLButtonElement>(null);
    const scroller = useRef<HTMLDivElement>(null);

    const ai = advisor.data?.ai;
    const ready = ai !== undefined && ai.configured && ai.figures_shared;

    useEffect(() => {
        // Into the question box; while there is none (loading, AI not set up) onto Close, so Escape works.
        if (open) (box.current ?? closeButton.current)?.focus({ preventScroll: true });
    }, [open, ready]);

    useEffect(() => {
        const el = scroller.current;
        if (el) el.scrollTop = el.scrollHeight;
    }, [exchanges]);

    const run = (id: number, q: string) => {
        setExchanges((list) => list.map((e) => (e.id === id ? { id, question: q } : e)));
        ask.mutate(
            { question: q, answerIn },
            {
                onSuccess: (answer) => setExchanges((list) => list.map((e) => (e.id === id ? { ...e, answer } : e))),
                onError: (error) => setExchanges((list) => list.map((e) => (e.id === id ? { ...e, error } : e))),
            },
        );
    };

    const send = (text: string) => {
        const q = text.trim();
        if (q.length < 3 || ask.isPending || !ready) return;
        const id = nextId.current++;
        setExchanges((list) => [...list, { id, question: q }]);
        setQuestion('');
        run(id, q);
    };

    const onSubmit = (event: FormEvent) => {
        event.preventDefault();
        send(question);
    };

    const onBoxKey = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        // Enter sends; Shift+Enter is a new line; never send mid-composition (Bangla IMEs).
        if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
            event.preventDefault();
            send(question);
        }
    };

    const onPanelKey = (event: KeyboardEvent<HTMLElement>) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            onClose();
        }
    };

    const startOver = () => {
        ask.reset();
        setExchanges([]);
        setQuestion('');
        box.current?.focus();
    };

    return (
        <section aria-labelledby={`${ids}-title`} onKeyDown={onPanelKey} className="flex h-full flex-col border-s border-line-default bg-surface">
            <div className="flex h-15 shrink-0 items-center gap-3 border-b border-line-default px-6">
                <span aria-hidden className="flex size-7 shrink-0 items-center justify-center rounded-xs bg-brand-subtle text-fg-brand">
                    <SparkleIcon size={18} weight="fill" />
                </span>
                <h2 id={`${ids}-title`} className="min-w-0 flex-1 truncate t-admin-section text-fg-primary">
                    {t('askAi.title')}
                </h2>
                <Button variant="ghost" size="sm" onClick={startOver} disabled={exchanges.length === 0} title={t('askAi.newHint')}>
                    <NotePencilIcon size={16} aria-hidden />
                    {t('askAi.new')}
                </Button>
                <IconButton ref={closeButton} onClick={onClose} aria-label={t('askAi.close')} title={t('askAi.close')}>
                    <XIcon size={20} aria-hidden />
                </IconButton>
            </div>

            <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-line-default px-6 py-3">
                <span className="t-admin-caption text-fg-secondary">{t('askAi.answerIn')}</span>
                <SegmentedControl
                    label={t('askAi.answerIn')}
                    value={answerIn}
                    onChange={setAnswerIn}
                    options={[
                        { value: 'bn', label: 'বাংলা', lang: 'bn' },
                        { value: 'en', label: 'English', lang: 'en' },
                    ]}
                />
                <span className="flex-1" />
                {ai && <StatusChip label={t(`aiAdvisor.provider.${ai.provider}` as MessageKey)} tone="brand" />}
            </div>

            <div ref={scroller} className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-6 py-5">
                <p className="t-admin-body text-fg-secondary">{t('askAi.intro')}</p>
                {advisor.isError ? (
                    <QueryError error={advisor.error} onRetry={() => void advisor.refetch()} />
                ) : !ai ? (
                    <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                        {t('aiAdvisor.loading')}
                    </p>
                ) : !ai.figures_shared ? (
                    <FiguresOff />
                ) : !ai.configured ? (
                    <AiErrorNotice error={new ApiError(409, { type: PROBLEM + 'ai-not-configured', title: '', status: 409 })} />
                ) : (
                    <div className="flex flex-col gap-4" aria-live="polite">
                        {exchanges.map((exchange) => (
                            <div key={exchange.id} className="flex flex-col gap-4">
                                <p className="max-w-[85%] self-end rounded-md bg-brand px-3 py-2 t-admin-body break-words text-fg-on-brand">{exchange.question}</p>
                                {exchange.answer ? (
                                    <PanelAnswer answer={exchange.answer} />
                                ) : exchange.error ? (
                                    exchange.error instanceof ApiError && exchange.error.status === 429 ? (
                                        <p role="alert" className="rounded-lg bg-subtle p-4 t-admin-body text-fg-primary">
                                            {t('aiAdvisor.tooMany')}
                                        </p>
                                    ) : (
                                        <AiErrorNotice error={exchange.error} onRetry={() => run(exchange.id, exchange.question)} />
                                    )
                                ) : (
                                    <p className="flex items-center gap-2 rounded-lg bg-subtle p-4 t-admin-body text-fg-secondary" aria-busy="true">
                                        <SparkleIcon size={16} aria-hidden className="shrink-0 text-fg-brand motion-safe:animate-pulse" />
                                        {t('aiAdvisor.ask.thinking')}
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {ready && (
                <form onSubmit={onSubmit} className="flex shrink-0 flex-col gap-3 border-t border-line-default px-6 py-4">
                    <ul aria-label={t('askAi.suggestions')} className="flex flex-wrap gap-2">
                        {suggestions.map((s) => (
                            <li key={s.chip}>
                                <button
                                    type="button"
                                    onClick={() => send(t(s.question))}
                                    disabled={ask.isPending}
                                    title={t(s.question)}
                                    className="h-8 rounded-full border border-line-default bg-surface px-3 t-admin-caption-strong whitespace-nowrap text-fg-primary hover:bg-subtle disabled:text-fg-disabled"
                                >
                                    {t(s.chip)}
                                </button>
                            </li>
                        ))}
                    </ul>
                    <label htmlFor={`${ids}-q`} className="sr-only">
                        {t('aiAdvisor.ask.label')}
                    </label>
                    <textarea
                        ref={box}
                        id={`${ids}-q`}
                        rows={2}
                        maxLength={500}
                        value={question}
                        onChange={(e) => setQuestion(e.target.value)}
                        onKeyDown={onBoxKey}
                        placeholder={t('askAi.placeholder')}
                        aria-describedby={`${ids}-hint`}
                        className={`${inputClass} h-19 resize-none py-3`}
                    />
                    <div className="flex items-center gap-3">
                        <p id={`${ids}-hint`} className="min-w-0 flex-1 t-admin-caption text-fg-tertiary">
                            {t('askAi.hint')}
                        </p>
                        <Button type="submit" disabled={ask.isPending || question.trim().length < 3}>
                            <PaperPlaneRightIcon size={16} aria-hidden />
                            {t('askAi.send')}
                        </Button>
                    </div>
                </form>
            )}
        </section>
    );
}

function FiguresOff() {
    const { t } = useI18n();
    return (
        <div role="alert" className="flex flex-col items-start gap-3 rounded-md border border-line-default bg-subtle p-4">
            <div className="flex items-start gap-3">
                <WarningIcon size={20} aria-hidden className="mt-0.5 shrink-0 text-feedback-warning" />
                <div className="flex min-w-0 flex-col gap-1">
                    <p className="t-admin-body-strong text-fg-primary">{t('aiAdvisor.figuresOff.title')}</p>
                    <p className="t-admin-body text-fg-secondary">{t('aiAdvisor.figuresOff.body')}</p>
                </div>
            </div>
            <Link to="/integrations/ai" className={buttonClass('outline', 'sm')}>
                {t('ai.problem.openSettings')}
            </Link>
        </div>
    );
}

/** Figma "answer" 603:52116: bulleted sentences, source chips on surface, a sparkle byline. */
function PanelAnswer({ answer }: { answer: AdvisorAnswer }) {
    const { t } = useI18n();
    return (
        <div className="flex flex-col gap-3 rounded-lg bg-subtle p-4">
            <ul className="flex flex-col gap-3">
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
            <p className="flex items-center gap-1 t-admin-caption text-fg-tertiary">
                <SparkleIcon size={12} aria-hidden className="shrink-0" />
                {t('askAi.checked', { model: modelLabel(answer.model) })}
            </p>
        </div>
    );
}
