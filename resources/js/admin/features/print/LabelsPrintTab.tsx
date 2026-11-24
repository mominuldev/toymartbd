import { useEffect, useId, useState, type FormEvent } from 'react';
import { updatePrintSettingsRequestSchema } from '@toymart/api-types/zod';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { can } from '@/features/auth/api';
import { StickySaveBar, UnsavedChangesGuard, useChangeSummary, useSavedFlash, useSettingsAccess } from '@/features/storeSettings/parts';
import { useI18n } from '@/i18n/I18nProvider';
import { ApiError } from '@/lib/api';
import { usePrintSettings, useSavePrintSettings, type LabelsPrint } from './api';
import { LABEL_PAPER, LABEL_PAPERS, LabelPreview, type LabelPaper } from './LabelPreview';
import { LabelsPrintJob } from './LabelsPrintJob';
import { OptionSection, PreviewPanel, PrintLayout, PrintTabs, ToggleRow } from './parts';

const FORM_ID = 'print-labels-form';

/** The "What the label shows" toggles, in Figma order. */
const SHOWS = [
    { field: 'show_name', label: 'printLabels.shows.name' },
    { field: 'show_options', label: 'printLabels.shows.options' },
    { field: 'show_price', label: 'printLabels.shows.price', hint: 'printLabels.shows.priceHint' },
    { field: 'show_store', label: 'printLabels.shows.store' },
    { field: 'show_digits', label: 'printLabels.shows.digits' },
] as const;

/**
 * Settings › Invoices, receipts & labels › Barcode labels (Figma 529:82666): the label paper,
 * what each label shows and whether missing barcodes are created when printing — read with
 * settings.view, saved whole with settings.update (PUT {labels}). Beside them, a live sample
 * label at true proportions and, for someone with catalog.view, a print job that sends these
 * options to POST /products/labels. Printing from a product page uses the saved values.
 */
export function LabelsPrintTab() {
    const { t } = useI18n();
    const { me, canView, canEdit } = useSettingsAccess();
    const settings = usePrintSettings(canView);

    let body;
    if (me && !canView) body = <p className="t-admin-body text-fg-secondary">{t('error.forbidden')}</p>;
    else if (settings.isError) body = <QueryError error={settings.error} onRetry={() => void settings.refetch()} />;
    else if (!settings.data) body = <p aria-busy="true" className="sr-only" />;
    else body = <LabelsForm saved={settings.data.labels} editable={canEdit} canPrint={can(me, 'catalog.view')} canGenerate={can(me, 'catalog.update')} />;

    return (
        <section className="flex min-w-0 flex-col gap-6">
            <PrintTabs />
            {body}
        </section>
    );
}

function LabelsForm({ saved, editable, canPrint, canGenerate }: { saved: LabelsPrint; editable: boolean; canPrint: boolean; canGenerate: boolean }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useSavePrintSettings();
    const [draft, setDraft] = useState<LabelsPrint>(saved);
    const [justSaved, flashSaved] = useSavedFlash();
    useEffect(() => setDraft(saved), [saved]);

    const set = <K extends keyof LabelsPrint>(field: K, value: LabelsPrint[K]) => setDraft((current) => ({ ...current, [field]: value }));
    const paperChanged = draft.template !== saved.template;
    const showsChanged = SHOWS.some(({ field }) => draft[field] !== saved[field]);
    const barcodesChanged = draft.generate_missing !== saved.generate_missing;
    const dirty = paperChanged || showsChanged || barcodesChanged;
    const summary = useChangeSummary(
        [paperChanged && t('printLabels.changed.paper'), showsChanged && t('printLabels.changed.shows'), barcodesChanged && t('printLabels.changed.barcodes')].filter((x): x is string => typeof x === 'string'),
    );
    const disabled = !editable || save.isPending;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const parsed = updatePrintSettingsRequestSchema.safeParse({ labels: draft });
        if (!parsed.success) {
            notify(t('error.title'), 'danger');
            return;
        }
        save.mutate(parsed.data, {
            onSuccess: () => flashSaved(),
            onError: (e) => notify(e instanceof ApiError ? (e.problem.errors?.[0]?.message ?? e.problem.title) : t('error.title'), 'danger'),
        });
    };

    const paper = LABEL_PAPER[draft.template];

    return (
        <>
            {editable && <UnsavedChangesGuard when={dirty} />}
            {editable && (
                <StickySaveBar
                    section={t('print.tab.labels')}
                    dirty={dirty}
                    saved={justSaved}
                    savedLabel={t('printLabels.saved')}
                    summary={summary}
                    saving={save.isPending}
                    onDiscard={() => setDraft(saved)}
                    form={FORM_ID}
                />
            )}
            <PrintLayout
                before={canPrint ? <LabelsPrintJob value={draft} canGenerate={canGenerate} /> : undefined}
                options={
                    // Only the options are the form: Enter in the print job's fields never saves settings.
                    <form id={FORM_ID} onSubmit={submit} noValidate aria-label={t('printLabels.form')} className="flex flex-col">
                        <OptionSection title={t('printLabels.paper.title')} hint={t('printLabels.paper.hint')}>
                            <PaperChoice value={draft.template} onChange={(value) => set('template', value)} disabled={disabled} />
                        </OptionSection>
                        <OptionSection title={t('printLabels.shows.title')}>
                            {SHOWS.map((row) => (
                                <ToggleRow
                                    key={row.field}
                                    label={t(row.label)}
                                    hint={'hint' in row ? t(row.hint) : undefined}
                                    checked={draft[row.field]}
                                    onChange={(value) => set(row.field, value)}
                                    disabled={disabled}
                                />
                            ))}
                        </OptionSection>
                        <OptionSection title={t('printLabels.barcodes.title')} hint={t('printLabels.barcodes.hint')}>
                            <ToggleRow
                                label={t('printLabels.barcodes.generate')}
                                hint={t('printLabels.barcodes.generateHint')}
                                checked={draft.generate_missing}
                                onChange={(value) => set('generate_missing', value)}
                                disabled={disabled}
                            />
                        </OptionSection>
                    </form>
                }
                preview={
                    <PreviewPanel title={t('printLabels.preview.title')}>
                        <figure className="flex min-w-0 flex-col gap-3">
                                <figcaption className="t-admin-caption tabular text-fg-secondary">
                                    {paper.sheet
                                        ? t('printLabels.preview.sheetCaption', { paper: t(`printLabels.paper.${draft.template}`), width: paper.width, height: paper.height })
                                        : t('printLabels.preview.caption', { paper: t(`printLabels.paper.${draft.template}`) })}
                                </figcaption>
                                <LabelPreview value={draft} />
                        </figure>
                    </PreviewPanel>
                }
            />
        </>
    );
}

/** The four label stocks as radio cards (an accessible radio group, Figma Radio · on / off). */
export function PaperChoice({ value, onChange, disabled, className = 'flex flex-col gap-3' }: { value: LabelPaper; onChange: (value: LabelPaper) => void; disabled: boolean; className?: string }) {
    const { t } = useI18n();
    const name = useId();
    return (
        <div role="radiogroup" aria-label={t('printLabels.paper.title')} className={className}>
            {LABEL_PAPERS.map((option) => {
                const checked = value === option;
                const size = LABEL_PAPER[option];
                return (
                    <label
                        key={option}
                        className={`flex min-h-11 items-center gap-3 rounded-sm px-4 py-3 transition-colors duration-150 ease-standard has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-line-focus ${
                            checked ? 'border-[1.5px] border-line-brand bg-brand-subtle' : 'border border-line-default bg-surface hover:bg-subtle'
                        } ${disabled ? 'cursor-default' : 'cursor-pointer'}`}
                    >
                        <input type="radio" name={name} value={option} checked={checked} disabled={disabled} onChange={() => onChange(option)} className="sr-only" />
                        <span aria-hidden className={`grid size-4.5 shrink-0 place-items-center rounded-full ${checked ? 'bg-brand' : 'border-[1.5px] border-line-strong bg-surface'}`}>
                            {checked && <span className="size-1.75 rounded-full bg-surface" />}
                        </span>
                        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span className="t-admin-body-strong text-fg-primary">{t(`printLabels.paper.${option}`)}</span>
                            <span className="t-admin-caption tabular text-fg-secondary">
                                {size.sheet ? t('printLabels.paper.sheetHint', { width: size.width, height: size.height }) : t('printLabels.paper.rollHint')}
                            </span>
                        </span>
                    </label>
                );
            })}
        </div>
    );
}
