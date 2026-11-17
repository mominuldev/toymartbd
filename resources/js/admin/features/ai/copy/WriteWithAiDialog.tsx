import { useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link } from 'react-router';
import { ArrowsClockwiseIcon, CheckIcon, InfoIcon, MagicWandIcon, SparkleIcon, WarningIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button, buttonClass } from '@/components/ui/Button';
import { controlHeight, inputClass } from '@/components/ui/Field';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { AiErrorNotice, modelLabel } from '../AiNotice';
import {
    copyFields,
    copyLimits,
    isNotShared,
    useProductCopyFacts,
    useWriteProductCopy,
    type CopyFact,
    type CopyField,
    type CopyLanguage,
    type CopyTexts,
    type CopyTone,
    type ProductCopy,
    type ProductCopyDraft,
} from './api';

type Lang = 'en' | 'bn';
type TextKey = 'title' | 'description_html' | 'seo_title' | 'meta_description';
type Edits = Partial<Record<Lang, Partial<Record<TextKey, string>>>>;

type Props = {
    productId: number;
    onClose: () => void;
    /** Fills the product form (marking it dirty). The product is saved only by Save product. */
    onUse: (texts: CopyTexts) => void;
};

const fieldLabel: Record<CopyField, MessageKey> = { title: 'aiCopy.field.title', description: 'aiCopy.field.description', seo: 'aiCopy.field.seo' };
const toneLabel: Record<CopyTone, MessageKey> = { warm: 'aiCopy.tone.warm', playful: 'aiCopy.tone.playful', premium: 'aiCopy.tone.premium' };
const factLabel: Partial<Record<CopyFact['key'], MessageKey>> = {
    name: 'aiCopy.fact.name',
    name_bn: 'aiCopy.fact.name_bn',
    category: 'aiCopy.fact.category',
    brand: 'aiCopy.fact.brand',
    age: 'aiCopy.fact.age',
    warranty: 'aiCopy.fact.warranty',
    safety_note: 'aiCopy.fact.safety_note',
    safety_note_bn: 'aiCopy.fact.safety_note_bn',
    description: 'aiCopy.fact.description',
    description_bn: 'aiCopy.fact.description_bn',
};
const genderLabel: Record<string, MessageKey> = { boys: 'products.gender.boys', girls: 'products.gender.girls', unisex: 'products.gender.unisex' };

/** Characters as the server counts them (code points, PHP mb_strlen). */
const length = (text: string) => Array.from(text).length;

/**
 * Product › Write with AI (Figma 538:82911). Left, on canvas: what to write, language, tone,
 * keywords and the facts the server gave the AI. Right: the draft per language — title, a
 * rendered description, SEO title and meta description, each editable. "Use these texts" fills
 * the product form and marks it dirty; nothing is saved until Save product (the slug is never
 * touched, rule 37). Every limit, figure check and the HTML allowlist is the server's (rule 1).
 */
export function WriteWithAiDialog({ productId, onClose, onUse }: Props) {
    const { t } = useI18n();
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const write = useWriteProductCopy(productId);
    const factsQuery = useProductCopyFacts(productId);
    const [fields, setFields] = useState<CopyField[]>([...copyFields]);
    const [language, setLanguage] = useState<CopyLanguage>('both');
    const [tone, setTone] = useState<CopyTone>('warm');
    const [keywords, setKeywords] = useState('');
    const [draft, setDraft] = useState<ProductCopyDraft | null>(null);
    const [edits, setEdits] = useState<Edits>({});
    const [view, setView] = useState<Lang>('en');
    /** Bumped per draft, so the description editables load the new HTML. */
    const [version, setVersion] = useState(0);

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog || dialog.open) return;
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else dialog.setAttribute('open', '');
    }, []);

    const busy = write.isPending;
    const languages: Lang[] = draft ? (['en', 'bn'] as const).filter((l) => draft[l] !== null) : [];
    const shown: Lang = languages.includes(view) ? view : (languages[0] ?? 'en');

    const run = () => {
        if (fields.length === 0 || busy) return;
        const body = { fields: copyFields.filter((f) => fields.includes(f)), language, tone, ...(keywords.trim() ? { keywords: keywords.trim() } : {}) };
        write.mutate(body, {
            onSuccess: (next) => {
                setDraft(next);
                setEdits({});
                setVersion((v) => v + 1);
                setView(next.en ? 'en' : 'bn');
            },
        });
    };

    const textOf = (lang: Lang, key: TextKey): string | null => edits[lang]?.[key] ?? draft?.[lang]?.[key] ?? null;
    const edit = (lang: Lang, key: TextKey, value: string) => setEdits((current) => ({ ...current, [lang]: { ...current[lang], [key]: value } }));

    const use = () => {
        if (!draft) return;
        const texts: CopyTexts = {};
        for (const lang of languages) {
            const out: Partial<Record<TextKey, string>> = {};
            for (const key of ['title', 'description_html', 'seo_title', 'meta_description'] as const) {
                const value = textOf(lang, key);
                if (value !== null) out[key] = value;
            }
            texts[lang] = out;
        }
        onUse(texts);
        onClose();
    };

    const toggleField = (field: CopyField, on: boolean) => setFields((current) => (on ? [...current, field] : current.filter((f) => f !== field)));

    return createPortal(
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            aria-busy={busy || undefined}
            onCancel={(event) => {
                event.preventDefault();
                if (!busy) onClose();
            }}
            className="m-auto w-[min(100%-2rem,64rem)] overflow-hidden rounded-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay"
        >
            <div className="flex max-h-[90vh] flex-col">
                {/* Header */}
                <div className="flex items-start gap-3 border-b border-line-default px-6 py-4">
                    <MagicWandIcon size={20} aria-hidden className="mt-0.5 shrink-0 text-fg-brand" />
                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                        <h2 id={titleId} className="t-admin-section text-fg-primary">
                            {t('aiCopy.title')}
                        </h2>
                        <p className="t-admin-caption text-fg-secondary">{t('aiCopy.subtitle')}</p>
                    </div>
                    {draft && <span className="shrink-0 rounded-sm bg-subtle px-2 py-1 t-admin-caption-strong text-fg-primary">{modelLabel(draft.model)}</span>}
                    <button type="button" aria-label={t('aiCopy.close')} onClick={onClose} disabled={busy} className="inline-grid size-8 shrink-0 place-items-center rounded-sm text-fg-secondary hover:bg-subtle hover:text-fg-primary">
                        <XIcon size={16} aria-hidden />
                    </button>
                </div>

                <div className="grid min-h-0 flex-1 overflow-y-auto md:grid-cols-[22rem_minmax(0,1fr)]">
                    {/* Left: choices and facts, on canvas */}
                    <div className="flex flex-col gap-5 bg-canvas p-6">
                        <fieldset className="flex flex-col gap-2.5">
                            <legend className="mb-2.5 t-admin-body-strong text-fg-primary">{t('aiCopy.what')}</legend>
                            {copyFields.map((field) => (
                                <label key={field} className="flex items-center gap-2 t-admin-body text-fg-primary">
                                    <input type="checkbox" checked={fields.includes(field)} onChange={(e) => toggleField(field, e.target.checked)} disabled={busy} className="size-4 accent-brand" />
                                    {t(fieldLabel[field])}
                                </label>
                            ))}
                            {fields.length === 0 && <p className="t-admin-caption text-fg-secondary">{t('aiCopy.pickOne')}</p>}
                        </fieldset>

                        <div className="flex flex-col gap-1.5">
                            <span className="t-admin-body-strong text-fg-primary">{t('aiCopy.language')}</span>
                            <SegmentedControl
                                label={t('aiCopy.language')}
                                value={language}
                                onChange={setLanguage}
                                className="self-start"
                                options={[
                                    { value: 'en', label: t('aiCopy.language.en') },
                                    { value: 'bn', label: t('aiCopy.language.bn'), lang: 'bn' },
                                    { value: 'both', label: t('aiCopy.language.both') },
                                ]}
                            />
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <span className="t-admin-body-strong text-fg-primary">{t('aiCopy.tone')}</span>
                            <SegmentedControl label={t('aiCopy.tone')} value={tone} onChange={setTone} className="self-start" options={(['warm', 'playful', 'premium'] as const).map((value) => ({ value, label: t(toneLabel[value]) }))} />
                        </div>

                        <label className="flex flex-col gap-1.5">
                            <span className="t-admin-body-strong text-fg-primary">{t('aiCopy.keywords')}</span>
                            <input value={keywords} maxLength={120} onChange={(e) => setKeywords(e.target.value)} placeholder={t('aiCopy.keywordsPlaceholder')} disabled={busy} className={`${controlHeight.md} ${inputClass}`} />
                        </label>

                        <section aria-labelledby={`${titleId}-facts`} className="flex flex-col gap-2 rounded-md border border-line-default bg-surface p-4">
                            <h3 id={`${titleId}-facts`} className="t-admin-overline text-fg-secondary">
                                {t('aiCopy.facts')}
                            </h3>
                            {(draft?.facts ?? factsQuery.data?.facts) ? (
                                <ul className="flex list-disc flex-col gap-1 ps-4 t-admin-caption text-fg-primary">
                                    {(draft?.facts ?? factsQuery.data?.facts ?? []).map((fact, i) => (
                                        <li key={`${fact.key}-${i}`}>
                                            <FactLine fact={fact} />
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="t-admin-caption text-fg-secondary">{t('aiCopy.factsEmpty')}</p>
                            )}
                            <p className="t-admin-caption text-fg-secondary">{t('aiCopy.factsNote')}</p>
                        </section>

                        <Button onClick={run} disabled={busy || fields.length === 0} className="w-full">
                            <SparkleIcon size={16} aria-hidden />
                            {busy ? t('aiCopy.writing') : t('aiCopy.write')}
                        </Button>
                    </div>

                    {/* Right: the draft */}
                    <div className="flex min-w-0 flex-col gap-4 p-6" aria-live="polite">
                        {draft && !busy && (
                            <div className="flex flex-wrap items-center gap-3">
                                {languages.length > 1 && (
                                    <SegmentedControl
                                        label={t('aiCopy.draftLanguage')}
                                        value={shown}
                                        onChange={setView}
                                        options={[
                                            { value: 'en', label: t('aiCopy.language.en') },
                                            { value: 'bn', label: t('aiCopy.language.bn'), lang: 'bn' },
                                        ]}
                                    />
                                )}
                                <Button variant="ghost" size="sm" onClick={run} disabled={fields.length === 0} className="ms-auto">
                                    <ArrowsClockwiseIcon size={16} aria-hidden />
                                    {t('aiCopy.again')}
                                </Button>
                            </div>
                        )}

                        {busy ? (
                            <div className="flex flex-col gap-4" aria-busy="true">
                                <p className="t-admin-caption text-fg-secondary">{t('aiCopy.loading')}</p>
                                <div className="h-10 rounded-sm bg-skeleton motion-safe:animate-pulse" />
                                <div className="h-36 rounded-sm bg-skeleton motion-safe:animate-pulse" />
                                <div className="h-10 rounded-sm bg-skeleton motion-safe:animate-pulse" />
                                <div className="h-16 rounded-sm bg-skeleton motion-safe:animate-pulse" />
                            </div>
                        ) : write.isError ? (
                            isNotShared(write.error) ? (
                                <div role="alert" className="flex flex-wrap items-start gap-3 rounded-md border border-line-default bg-subtle p-4">
                                    <WarningIcon size={20} aria-hidden className="mt-0.5 shrink-0 text-feedback-warning" />
                                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                                        <p className="t-admin-body-strong text-fg-primary">{t('aiCopy.notShared.title')}</p>
                                        <p className="t-admin-body text-fg-secondary">{t('aiCopy.notShared.body')}</p>
                                    </div>
                                    <Link to="/integrations/ai" className={buttonClass('outline', 'sm')}>
                                        {t('ai.problem.openSettings')}
                                    </Link>
                                </div>
                            ) : (
                                <AiErrorNotice error={write.error} onRetry={run} />
                            )
                        ) : draft && draft[shown] ? (
                            <DraftFields key={`${version}-${shown}`} lang={shown} copy={draft[shown]} textOf={(key) => textOf(shown, key)} onEdit={(key, value) => edit(shown, key, value)} />
                        ) : (
                            <p className="t-admin-body text-fg-secondary">{t('aiCopy.empty')}</p>
                        )}
                    </div>
                </div>

                {/* Footer */}
                <div className="flex flex-wrap items-center justify-end gap-3 border-t border-line-default px-6 py-4">
                    <p className="me-auto flex items-center gap-1.5 t-admin-caption text-fg-secondary">
                        <InfoIcon size={14} aria-hidden className="shrink-0" />
                        {t('aiCopy.footer')}
                    </p>
                    <Button variant="ghost" onClick={onClose} disabled={busy}>
                        {t('aiCopy.cancel')}
                    </Button>
                    <Button onClick={use} disabled={busy || !draft || languages.length === 0}>
                        <CheckIcon size={16} aria-hidden />
                        {t('aiCopy.use')}
                    </Button>
                </div>
            </div>
        </dialog>,
        document.body,
    );
}

function FactLine({ fact }: { fact: CopyFact }) {
    const { t } = useI18n();
    if (fact.key === 'price') return <>{t('aiCopy.fact.price', { price: fact.value })}</>;
    if (fact.key === 'educational') return <>{t('aiCopy.fact.educational')}</>;
    if (fact.key === 'gender') {
        const gender = genderLabel[fact.value];
        return <>{`${t('aiCopy.fact.gender')}: ${gender ? t(gender) : fact.value}`}</>;
    }
    const label = factLabel[fact.key];
    // spec and option values already read "Material: Wood" / "Colour: Red, Blue".
    return <span lang={fact.key.endsWith('_bn') ? 'bn' : undefined}>{label ? `${t(label)}: ${fact.value}` : fact.value}</span>;
}

type DraftFieldsProps = {
    lang: Lang;
    copy: ProductCopy;
    textOf: (key: TextKey) => string | null;
    onEdit: (key: TextKey, value: string) => void;
};

/** One language's draft. Remounted per draft and language, so the description editable loads fresh HTML. */
function DraftFields({ lang, copy, textOf, onEdit }: DraftFieldsProps) {
    const { t } = useI18n();
    const descId = useId();
    const bn = lang === 'bn';
    const textClass = bn ? 'lang-bn' : '';
    // The server-sanitised HTML (p, ul, li, strong only); loaded once, then the editable owns it.
    const [initialHtml] = useState(copy.description_html ?? '');
    const title = textOf('title');
    const desc = textOf('description_html');
    const seoTitle = textOf('seo_title');
    const meta = textOf('meta_description');

    return (
        <div className="flex flex-col gap-4">
            {copy.unknown_figures.length > 0 && (
                <p role="note" className="flex items-start gap-1.5 rounded-md bg-warning-subtle px-3 py-2 t-admin-caption text-fg-primary">
                    <WarningIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-warning" />
                    {t('aiCopy.unknownFigures', { figures: copy.unknown_figures.join(', ') })}
                </p>
            )}
            {title !== null && (
                <CountedField lang={lang} label={t('aiCopy.out.title')} value={title} max={copyLimits.title} onChange={(v) => onEdit('title', v)} />
            )}
            {desc !== null && (
                <div className="flex flex-col gap-1.5">
                    <span id={descId} className="t-admin-body-strong text-fg-primary">
                        {t('aiCopy.out.description')}
                    </span>
                    <div
                        role="textbox"
                        aria-multiline="true"
                        aria-labelledby={descId}
                        lang={lang}
                        contentEditable
                        suppressContentEditableWarning
                        onInput={(e) => onEdit('description_html', e.currentTarget.innerHTML)}
                        dangerouslySetInnerHTML={{ __html: initialHtml }}
                        className={`flex min-h-28 flex-col gap-2 rounded-sm border border-line-default bg-surface p-3.5 t-admin-body text-fg-primary outline-none focus:border-line-focus focus:ring-1 focus:ring-inset focus:ring-line-focus [&_li]:ps-0.5 [&_ul]:list-disc [&_ul]:ps-5 ${textClass}`}
                    />
                </div>
            )}
            {seoTitle !== null && (
                <CountedField lang={lang} label={t('aiCopy.out.seoTitle')} value={seoTitle} max={copyLimits.seo_title} onChange={(v) => onEdit('seo_title', v)} />
            )}
            {meta !== null && (
                <CountedField lang={lang} label={t('aiCopy.out.metaDescription')} value={meta} max={copyLimits.meta_description} multiline onChange={(v) => onEdit('meta_description', v)} />
            )}
        </div>
    );
}

type CountedFieldProps = { lang: Lang; label: string; value: string; max: number; multiline?: boolean; onChange: (value: string) => void };

/** A draft text with its "46 / 70" counter; over the limit (the server's `too_long` after its one retry) it says so. */
function CountedField({ lang, label, value, max, multiline = false, onChange }: CountedFieldProps) {
    const { t } = useI18n();
    const id = useId();
    const count = length(value);
    const over = count > max;
    const className = `${inputClass} ${lang === 'bn' ? 'lang-bn' : ''}`;
    return (
        <div className="flex flex-col gap-1.5">
            <div className="flex items-baseline gap-2">
                <label htmlFor={id} className="min-w-0 flex-1 t-admin-body-strong text-fg-primary">
                    {label}
                </label>
                <span className={`t-admin-caption tabular ${over ? 'text-fg-primary' : 'text-fg-tertiary'}`}>{t('aiCopy.count', { count, max })}</span>
            </div>
            {multiline ? (
                <textarea id={id} lang={lang} rows={3} value={value} onChange={(e) => onChange(e.target.value)} aria-invalid={over || undefined} aria-describedby={over ? `${id}-over` : undefined} className={`${className} py-2`} />
            ) : (
                <input id={id} lang={lang} value={value} onChange={(e) => onChange(e.target.value)} aria-invalid={over || undefined} aria-describedby={over ? `${id}-over` : undefined} className={`${controlHeight.md} ${className}`} />
            )}
            {over && (
                <p id={`${id}-over`} className="flex items-start gap-1 t-admin-caption text-fg-primary">
                    <WarningIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                    {t('aiCopy.tooLong', { max })}
                </p>
            )}
        </div>
    );
}
