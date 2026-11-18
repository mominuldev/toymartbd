import type { ReactNode } from 'react';
import { Link } from 'react-router';
import { InfoIcon, SparkleIcon, WarningIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { buttonClass } from '@/components/ui/Button';

/**
 * Shared AI pieces (Figma "AI for growth" 536:82212). Every AI surface shows who wrote the text
 * and shows a plain notice — never a raw error — when AI is not set up, over its monthly cap,
 * or the provider failed (problem types ai-not-configured / ai-cap-reached / ai-unavailable).
 */

export type AiProblem = 'not_configured' | 'cap_reached' | 'not_shared' | 'no_quota' | 'unavailable' | 'other';

export function aiProblem(error: unknown): AiProblem {
    if (!(error instanceof ApiError)) return 'other';
    const type = error.problem.type;
    if (type.endsWith('/ai-not-configured')) return 'not_configured';
    if (type.endsWith('/ai-cap-reached')) return 'cap_reached';
    if (type.endsWith('/ai-not-shared')) return 'not_shared';
    if (type.endsWith('/ai-unavailable')) return error.problem.errors?.some((e) => e.message === 'no_quota') ? 'no_quota' : 'unavailable';
    return 'other';
}

/** A calm, actionable notice for an AI call that did not produce an answer. */
export function AiErrorNotice({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
    const { t } = useI18n();
    const kind = aiProblem(error);
    const setup = kind === 'not_configured' || kind === 'cap_reached' || kind === 'not_shared' || kind === 'no_quota';
    return (
        <div role="alert" className="flex flex-wrap items-start gap-3 rounded-md border border-line-default bg-subtle p-4">
            <WarningIcon size={20} aria-hidden className="mt-0.5 shrink-0 text-feedback-warning" />
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <p className="t-admin-body-strong text-fg-primary">{t(`ai.problem.${kind}.title`)}</p>
                <p className="t-admin-body text-fg-secondary">{t(`ai.problem.${kind}.body`)}</p>
            </div>
            {setup ? (
                <Link to="/integrations/ai" className={buttonClass('outline', 'sm')}>
                    {t('ai.problem.openSettings')}
                </Link>
            ) : (
                onRetry && (
                    <button type="button" onClick={onRetry} className={buttonClass('outline', 'sm')}>
                        {t('ai.problem.retry')}
                    </button>
                )
            )}
        </div>
    );
}

/** "Written by Claude Opus 5.5 · …" under AI text. */
export function AiByline({ model, children }: { model: string; children?: ReactNode }) {
    const { t } = useI18n();
    return (
        <p className="flex items-start gap-1.5 t-admin-caption text-fg-secondary">
            <InfoIcon size={14} aria-hidden className="mt-0.5 shrink-0" />
            <span>
                {t('ai.byline', { model })}
                {children}
            </span>
        </p>
    );
}

/** The brand-subtle "In plain words" panel used by the brief and the restock note. */
export function AiPanel({ title, meta, children }: { title: string; meta?: ReactNode; children: ReactNode }) {
    return (
        <section className="flex flex-col gap-3 rounded-md bg-brand-subtle p-5 sm:p-6">
            <div className="flex flex-wrap items-center gap-2">
                <SparkleIcon size={20} aria-hidden className="text-fg-brand" />
                <h2 className="min-w-0 flex-1 t-admin-overline text-fg-brand">{title}</h2>
                {meta}
            </div>
            {children}
        </section>
    );
}

/** Readable model label from an id the server sent (display only). */
export function modelLabel(id: string): string {
    const known: Record<string, string> = {
        'claude-opus-5-5': 'Claude Opus 5.5',
        'claude-sonnet-5-5': 'Claude Sonnet 5.5',
        'claude-haiku-5-5': 'Claude Haiku 5.5',
        'gpt-6-astra': 'GPT-6 Astra',
        'gpt-6.1-sol': 'GPT-6.1 Sol',
        'gpt-6.1-terra': 'GPT-6.1 Terra',
        'gpt-6-luna': 'GPT-6 Luna',
        'gemini-4-carbon': 'Gemini 4 Carbon',
        'gemini-4-argon': 'Gemini 4 Argon',
        'gemini-4-hydrogen': 'Gemini 4 Hydrogen',
        'gemini-3.8-flash': 'Gemini 3.8 Flash',
        'gemini-3.5-flash-lite': 'Gemini 3.5 Flash-Lite',
    };
    return known[id] ?? id;
}
