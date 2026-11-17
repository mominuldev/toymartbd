import { CheckIcon, ClockIcon, SparkleIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { AiErrorNotice, modelLabel } from '@/features/ai/AiNotice';
import type { AiDraftState } from './api';

/**
 * Inbox › Suggested reply (Figma 539:83211). The card above the composer: who wrote it, the
 * draft (Bangla gets lang="bn" + .lang-bn, rule 38), the facts it used, and Use draft / Shorter /
 * In English|In Bangla / Discard. "Use draft" only fills the composer — a person sends.
 */
export function AiDraftCard({ state, onUse }: { state: AiDraftState; onUse: (text: string) => void }) {
    const { t } = useI18n();
    if (!state.open) return null;
    const { draft, pending, error } = state;

    if (error && !pending) {
        return (
            <div className="flex flex-col gap-2">
                <AiErrorNotice error={error} onRetry={() => state.request(state.lastStyle)} />
                <Button variant="ghost" size="sm" className="self-start" onClick={state.discard}>
                    {t('aiInbox.discard')}
                </Button>
            </div>
        );
    }

    const bangla = draft?.language === 'bn';
    const other = bangla ? 'english' : 'bangla';

    return (
        <section aria-label={t('aiInbox.title')} aria-busy={pending} className="flex flex-col gap-3 rounded-md bg-brand-subtle p-4 sm:p-5">
            <div className="flex flex-wrap items-center gap-2">
                <SparkleIcon size={18} aria-hidden className="shrink-0 text-fg-brand" />
                <h3 className="min-w-0 flex-1 t-admin-body-strong text-fg-brand">
                    {t('aiInbox.title')}
                    {draft && !pending && <> · {t(bangla ? 'aiInbox.lang.bn' : 'aiInbox.lang.en')}</>}
                </h3>
                {draft && !pending && <span className="t-admin-caption tabular text-fg-secondary">{t('aiInbox.meta', { model: modelLabel(draft.model), seconds: draft.seconds.toFixed(1) })}</span>}
            </div>

            {pending || !draft ? (
                <p role="status" className="t-admin-body text-fg-secondary">
                    {t('aiInbox.drafting')}
                </p>
            ) : (
                <>
                    <p lang={draft.language} className={`t-admin-body whitespace-pre-wrap break-words text-fg-primary ${bangla ? 'lang-bn' : ''}`}>
                        {draft.text}
                    </p>
                    {draft.based_on.length > 0 && (
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="t-admin-caption text-fg-secondary">{t('aiInbox.basedOn')}</span>
                            <ul className="contents">
                                {draft.based_on.map((label) => (
                                    <li key={label} className="rounded-sm border border-line-default bg-surface px-2 py-1 t-admin-caption-strong text-fg-primary">
                                        {label}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {!draft.window_open && (
                        <p role="status" className="flex items-start gap-2 rounded-sm bg-warning-subtle px-3 py-2 t-admin-caption text-fg-primary">
                            <ClockIcon size={16} aria-hidden className="mt-px shrink-0 text-feedback-warning" />
                            {t('aiInbox.windowClosed')}
                        </p>
                    )}
                </>
            )}

            <div className="flex flex-wrap items-center gap-2">
                {draft?.window_open && (
                    <Button size="sm" disabled={pending} onClick={() => onUse(draft.text)}>
                        <CheckIcon size={16} aria-hidden />
                        {t('aiInbox.use')}
                    </Button>
                )}
                <Button variant="outline" size="sm" disabled={pending || !draft} onClick={() => state.request('shorter')}>
                    {t('aiInbox.shorter')}
                </Button>
                <Button variant="outline" size="sm" disabled={pending || !draft} onClick={() => state.request(other)}>
                    {t(bangla ? 'aiInbox.inEnglish' : 'aiInbox.inBangla')}
                </Button>
                <Button variant="ghost" size="sm" className="ml-auto" onClick={state.discard}>
                    {t('aiInbox.discard')}
                </Button>
            </div>
            <p className="t-admin-caption text-fg-secondary">{t('aiInbox.footnote')}</p>
        </section>
    );
}

/** The composer's "Draft with AI" ghost button. */
export function AiDraftButton({ state }: { state: AiDraftState }) {
    const { t } = useI18n();
    return (
        <Button variant="ghost" size="sm" disabled={state.pending} onClick={() => state.request('default')}>
            <SparkleIcon size={16} aria-hidden />
            {t('aiInbox.draftWithAi')}
        </Button>
    );
}
