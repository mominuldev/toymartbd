import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { ClockIcon, PaperPlaneRightIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { en, type MessageKey } from '@/i18n/messages';
import { ApiError } from '@/lib/api';
import { Button } from '@/components/ui/Button';
import { inputClass } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { useSendTemplate, useTemplates, type ConversationDetail, type InboxTemplate } from './api';
import { dhakaDayTime, langProps } from './parts';

const isKey = (key: string): key is MessageKey => key in en;

/** A system template is named by its event (`notif.event.order.confirmed`); a staff-made one by its own name. */
export function useTemplateLabel(): (tpl: Pick<InboxTemplate, 'key' | 'name' | 'template'>) => string {
    const { t } = useI18n();
    return (tpl) => {
        if (tpl.name) return tpl.name;
        const key = `notif.event.${tpl.key}`;
        return isKey(key) ? t(key) : tpl.template;
    };
}

/** "{{1}} · Order number" — Meta's positional notation, plus the library's variable label when we have one. */
function useParameterLabel(): (p: InboxTemplate['parameters'][number]) => string {
    const { t } = useI18n();
    return (p) => {
        const key = `notif.var.${p.name}`;
        return `{{${p.position}}} · ${isKey(key) ? t(key) : t('inbox.template.value')}`;
    };
}

/** The preview with typed values in place of {{n}} — display only; the server renders the stored text. */
function fill(body: string, values: string[]): string {
    return body.replace(/\{\{(\d+)\}\}/g, (match, n: string) => {
        const value = values[Number(n) - 1]?.trim();
        return value ? value : match;
    });
}

/**
 * WhatsApp after the 24-hour window: the composer is replaced by this panel (Meta: outside the
 * window only an approved template may be sent). Pick an approved utility template, fill its
 * positional values, check the preview, send. The list, the language and the rendering of the
 * stored message are the server's; nothing here decides what may be sent.
 */
export function TemplatePanel({ detail }: { detail: ConversationDetail }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const label = useTemplateLabel();
    const parameterLabel = useParameterLabel();
    const templates = useTemplates(detail.id, true);
    const send = useSendTemplate(detail.id);
    const [selected, setSelected] = useState('');
    const [values, setValues] = useState<string[]>([]);
    const [error, setError] = useState<string | null>(null);
    const picker = useRef<HTMLSelectElement>(null);
    const ids = useId();
    const tpl = templates.data?.find((x) => x.template === selected) ?? null;
    const complete = tpl !== null && tpl.parameters.every((_, i) => (values[i] ?? '').trim() !== '');
    const closedAt = detail.reply.window_closes_at;

    useEffect(() => {
        setSelected('');
        setValues([]);
        setError(null);
    }, [detail.id]);

    const choose = (name: string) => {
        setSelected(name);
        setValues([]);
        setError(null);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!tpl || send.isPending) return;
        if (!complete) {
            setError(t('inbox.template.required'));
            return;
        }
        send.mutate(
            { template: tpl.template, language: tpl.language, parameters: tpl.parameters.map((_, i) => (values[i] ?? '').trim()) },
            {
                onSuccess: () => {
                    notify(t('inbox.toast.templateSent'));
                    choose('');
                    picker.current?.focus();
                },
                onError: (e: unknown) =>
                    setError(
                        e instanceof ApiError
                            ? (e.fieldError('template') ?? e.fieldError('parameters') ?? e.problem.errors?.[0]?.message ?? e.problem.detail ?? t('inbox.toast.failed'))
                            : t('inbox.toast.failed'),
                    ),
            },
        );
    };

    const preview = tpl ? fill(tpl.body, values) : '';
    const previewLang = langProps(preview);

    return (
        <section aria-labelledby={`${ids}-title`} className="flex flex-col gap-3">
            <div className="flex items-start gap-2 rounded-sm bg-warning-subtle px-3 py-2">
                <ClockIcon size={16} aria-hidden className="mt-px shrink-0 text-feedback-warning" />
                <div className="flex min-w-0 flex-col gap-0.5">
                    <h3 id={`${ids}-title`} className="t-admin-caption-strong text-fg-primary">
                        {t('inbox.template.title')}
                    </h3>
                    <p className="t-admin-caption text-fg-primary">
                        {closedAt ? t('inbox.template.intro', { time: dhakaDayTime(closedAt, locale) }) : t('inbox.template.introNever')}
                    </p>
                </div>
            </div>

            {templates.isError ? (
                <QueryError error={templates.error} onRetry={() => void templates.refetch()} />
            ) : templates.isPending ? null : templates.data.length === 0 ? (
                <p role="status" className="t-admin-caption text-fg-secondary">
                    {t('inbox.template.none')}
                </p>
            ) : (
                <form onSubmit={submit} noValidate className="flex flex-col gap-3">
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${ids}-pick`} className="t-admin-body-strong text-fg-primary">
                            {t('inbox.template.pick')}
                        </label>
                        <Select id={`${ids}-pick`} ref={picker} size="sm" value={selected} onChange={(e) => choose(e.target.value)} aria-describedby={`${ids}-lang`}>
                            <option value="">{t('inbox.template.pickPlaceholder')}</option>
                            {templates.data.map((x) => (
                                <option key={x.template} value={x.template}>
                                    {label(x)}
                                </option>
                            ))}
                        </Select>
                        <p id={`${ids}-lang`} className="t-admin-caption text-fg-secondary">
                            {t('inbox.template.language', { language: t(templates.data[0]?.locale === 'en' ? 'inbox.template.lang.en' : 'inbox.template.lang.bn') })}
                        </p>
                    </div>

                    {tpl && tpl.parameters.length > 0 && (
                        <fieldset className="flex flex-col gap-2">
                            <legend className="mb-1.5 t-admin-body-strong text-fg-primary">{t('inbox.template.values')}</legend>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {tpl.parameters.map((p, i) => (
                                    <div key={`${tpl.template}-${p.position}`} className="flex flex-col gap-1">
                                        <label htmlFor={`${ids}-p${p.position}`} className="t-admin-caption-strong text-fg-primary">
                                            {parameterLabel(p)}
                                        </label>
                                        <Input
                                            id={`${ids}-p${p.position}`}
                                            size="sm"
                                            value={values[i] ?? ''}
                                            maxLength={1024}
                                            placeholder={p.example}
                                            aria-invalid={error !== null && !(values[i] ?? '').trim() ? true : undefined}
                                            onChange={(e) => {
                                                const next = [...values];
                                                next[i] = e.target.value;
                                                setValues(next);
                                                setError(null);
                                            }}
                                        />
                                    </div>
                                ))}
                            </div>
                        </fieldset>
                    )}

                    {tpl && (
                        <div className="flex flex-col gap-1">
                            <p id={`${ids}-preview-label`} className="t-admin-caption-strong text-fg-primary">
                                {t('inbox.template.preview')}
                            </p>
                            <p
                                role="note"
                                aria-labelledby={`${ids}-preview-label`}
                                aria-describedby={`${ids}-preview-hint`}
                                lang={previewLang.lang}
                                className={`${inputClass} py-2 whitespace-pre-wrap break-words bg-canvas ${previewLang.className}`}
                            >
                                {preview}
                            </p>
                            <p id={`${ids}-preview-hint`} className="t-admin-caption text-fg-secondary">
                                {t('inbox.template.previewHint')}
                            </p>
                        </div>
                    )}

                    {error && (
                        <p role="alert" className="flex items-start gap-1 t-admin-caption text-fg-primary">
                            <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                            {error}
                        </p>
                    )}

                    <div className="flex justify-end">
                        <Button type="submit" size="sm" disabled={!tpl || send.isPending}>
                            <PaperPlaneRightIcon size={16} aria-hidden />
                            {t('inbox.template.send')}
                        </Button>
                    </div>
                </form>
            )}
        </section>
    );
}
