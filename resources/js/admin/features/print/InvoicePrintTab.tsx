import { useEffect, useState } from 'react';
import { useForm, useWatch, type FieldPath } from 'react-hook-form';
import { useQueryClient } from '@tanstack/react-query';
import { zodResolver } from '@hookform/resolvers/zod';
import { Link } from 'react-router';
import { ArrowRightIcon, ArrowSquareOutIcon, ImageIcon, InfoIcon, TrashIcon } from '@phosphor-icons/react';
import { updateInvoiceSettingsRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button, buttonClass } from '@/components/ui/Button';
import { Field, inputClass } from '@/components/ui/Field';
import { IconButton } from '@/components/ui/IconButton';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import type { Media } from '@/features/media';
import { MediaPickerModal, type InsertFailure } from '@/features/media/picker';
import { useInvoiceSettings, useSaveInvoiceSettings, type InvoiceSettings, type InvoiceSettingsInput } from '@/features/invoices/api';
import { StickySaveBar, UnsavedChangesGuard, useChangeSummary, useSavedFlash, useSettingsAccess } from '@/features/storeSettings/parts';
import { usePrintSettings, useSavePrintSettings, type InvoicePrint } from './api';
import { openInvoicePreview } from './invoiceApi';
import { InvoicePreview, type InvoiceStore } from './InvoicePreview';
import { ChoiceRow, OptionSection, PreviewPanel, PrintLayout, PrintTabs, ToggleRow } from './parts';

const FORM_ID = 'invoice-print-settings';

/**
 * The company details PUT /settings/invoice replaces as one document. The identity (name,
 * trade licence, BIN, hotline) is edited under Store settings › Business and sent back unchanged;
 * address, email, website and footer are edited here.
 */
const textFields = ['name_en', 'name_bn', 'address_en', 'address_bn', 'trade_licence', 'bin', 'hotline', 'email', 'website', 'footer_en', 'footer_bn'] as const;
type TextField = (typeof textFields)[number];
type FormValues = Record<TextField, string>;
type Logo = { id: number; url: string | null } | null;

const toggles = ['show_barcode', 'show_phone', 'show_email', 'show_sku', 'show_options', 'show_payment_stamp', 'show_track_qr', 'show_signatures'] as const;
type Toggle = (typeof toggles)[number] | 'show_bin';
const toggleHints: Partial<Record<Toggle, MessageKey>> = {
    show_barcode: 'printInvoice.show_barcodeHint',
    show_payment_stamp: 'printInvoice.show_payment_stampHint',
};
const choiceLabels: Record<'paper' | 'language' | 'colour', MessageKey> = {
    paper: 'printInvoice.paper',
    language: 'printInvoice.language',
    colour: 'printInvoice.colour',
};

const validateInput = zodResolver(updateInvoiceSettingsRequestSchema);

function toForm(s: InvoiceSettings): FormValues {
    return Object.fromEntries(textFields.map((f) => [f, s[f] ?? ''])) as FormValues;
}

function toLogo(s: InvoiceSettings): Logo {
    return s.logo_media_id === null ? null : { id: s.logo_media_id, url: s.logo_url };
}

function toInput(v: FormValues, logo: number | null): InvoiceSettingsInput {
    const out: Record<string, string | number | null> = { logo_media_id: logo };
    for (const f of textFields) out[f] = v[f].trim() === '' ? null : v[f].trim();
    return out as InvoiceSettingsInput;
}

const blank = (value: string): string | null => (value.trim() === '' ? null : value.trim());

/**
 * Settings › Invoices, receipts & labels › A4 invoice (Figma 528:81351): how the customer invoice
 * prints — paper, language, colour, the optional parts — plus the store details it carries
 * (address, email, website, logo, footer; formerly Taxes & invoices). Options on the left, a live
 * preview of a sample invoice on the right. One save sends whichever of the two documents
 * changed: PUT /settings/invoice (details, copied onto invoices issued from now on) and
 * PUT /settings/print `{invoice}` (layout, read whenever an invoice prints). Read with
 * settings.view, edited with settings.update. Nothing here computes a figure (rule 1).
 */
export function InvoicePrintTab() {
    const { t } = useI18n();
    const { me, canView, canEdit } = useSettingsAccess();
    const company = useInvoiceSettings(canView);
    const print = usePrintSettings(canView);

    if (me && !canView) return <p className="t-admin-body text-fg-secondary">{t('error.forbidden')}</p>;
    const failed = company.isError ? company : print.isError ? print : null;
    if (failed) return <QueryError error={failed.error} onRetry={() => void failed.refetch()} />;
    if (!company.data || !print.data) return <p aria-busy="true" className="sr-only" />;

    return <InvoicePrintForm company={company.data} saved={print.data.invoice} editable={canEdit} />;
}

function InvoicePrintForm({ company, saved, editable }: { company: InvoiceSettings; saved: InvoicePrint; editable: boolean }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const client = useQueryClient();
    const saveCompany = useSaveInvoiceSettings();
    const savePrint = useSavePrintSettings();
    const [draft, setDraft] = useState<InvoicePrint>(saved);
    const [logo, setLogo] = useState<Logo>(toLogo(company));
    const [logoError, setLogoError] = useState<string | null>(null);
    const [picking, setPicking] = useState(false);
    const [opening, setOpening] = useState(false);
    const { register, handleSubmit, setError, formState, reset, control } = useForm<FormValues>({
        defaultValues: toForm(company),
        // Validate the payload the API will receive, against the API's own schema (rule 27).
        resolver: async (values, context, options) => {
            const result = await validateInput(toInput(values, logo?.id ?? null), context, options as never);
            return Object.keys(result.errors).length > 0 ? { values: {}, errors: result.errors as never } : { values, errors: {} };
        },
    });
    useEffect(() => {
        reset(toForm(company));
        setLogo(toLogo(company));
    }, [company, reset]);
    useEffect(() => setDraft(saved), [saved]);
    const values = useWatch({ control });

    const [justSaved, flashSaved] = useSavedFlash();
    const logoChanged = (logo?.id ?? null) !== company.logo_media_id;
    const textChanged = textFields.filter((f) => formState.dirtyFields[f]);
    const companyDirty = textChanged.length > 0 || logoChanged;
    const printChanged = (Object.keys(draft) as (keyof InvoicePrint)[]).filter((key) => draft[key] !== saved[key]);
    const changed = [
        ...printChanged.map((key) => t(key in choiceLabels ? choiceLabels[key as keyof typeof choiceLabels] : (`printInvoice.${key}` as MessageKey))),
        ...textChanged.map((f) => t(`invoiceSettings.field.${f}` as MessageKey)),
        ...(logoChanged ? [t('invoiceSettings.logo')] : []),
    ];
    const dirty = changed.length > 0;
    const summary = useChangeSummary(changed);
    const saving = saveCompany.isPending || savePrint.isPending;
    const set = <K extends keyof InvoicePrint>(key: K, value: InvoicePrint[K]) => setDraft((current) => ({ ...current, [key]: value }));

    const discard = () => {
        reset(toForm(company));
        setLogo(toLogo(company));
        setLogoError(null);
        setDraft(saved);
    };

    const submit = handleSubmit(async (formValues) => {
        let stage: 'company' | 'print' = 'company';
        try {
            if (companyDirty) {
                await saveCompany.mutateAsync(toInput(formValues, logo?.id ?? null));
                // Store settings › Business shows the same rows; Site & branding the same logo.
                void client.invalidateQueries({ queryKey: ['settings', 'business'] });
                void client.invalidateQueries({ queryKey: ['settings', 'branding'] });
            }
            stage = 'print';
            if (printChanged.length > 0) await savePrint.mutateAsync({ invoice: draft });
            flashSaved();
        } catch (error) {
            if (error instanceof ApiError && error.status === 422 && stage === 'company') {
                for (const field of textFields) {
                    const message = error.fieldError(field);
                    if (message) setError(field as FieldPath<FormValues>, { message });
                }
                setLogoError(error.fieldError('logo_media_id') ?? null);
            }
            notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger');
        }
    });

    const insertLogo = async (picked: Media[]): Promise<InsertFailure[]> => {
        const media = picked[0];
        if (!media) return [];
        if (media.processing_status !== 'ready') return [{ media, reason: t('invoiceSettings.logoNotReady') }];
        setLogo({ id: media.id, url: media.urls.thumb ?? media.urls.medium });
        setLogoError(null);
        setPicking(false);
        return [];
    };

    const preview = async () => {
        setOpening(true);
        try {
            await openInvoicePreview(draft);
        } catch {
            notify(t('printInvoice.preview.failed'), 'danger');
        } finally {
            setOpening(false);
        }
    };

    const text = (field: TextField, opts: { bn?: boolean; required?: boolean; label?: MessageKey; area?: boolean } = {}) => (
        <Field label={t(opts.label ?? (`invoiceSettings.field.${field}` as MessageKey))} error={formState.errors[field]?.message} required={opts.required} optional={!opts.required}>
            {({ id, describedBy, invalid }) =>
                opts.area ? (
                    <textarea
                        id={id}
                        lang={opts.bn ? 'bn' : undefined}
                        rows={2}
                        aria-describedby={describedBy}
                        aria-invalid={invalid || undefined}
                        className={`field-sizing-content min-h-16 resize-y py-2 ${inputClass}`}
                        {...register(field)}
                    />
                ) : (
                    <input id={id} lang={opts.bn ? 'bn' : undefined} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${inputClass}`} {...register(field)} />
                )
            }
        </Field>
    );

    const toggle = (key: Toggle) => {
        const hint = toggleHints[key];
        return <ToggleRow key={key} label={t(`printInvoice.${key}` as MessageKey)} hint={hint ? t(hint) : undefined} checked={draft[key]} onChange={(value) => set(key, value)} disabled={!editable} />;
    };

    const store: InvoiceStore = {
        name_en: company.name_en,
        name_bn: company.name_bn,
        address_en: blank(values.address_en ?? ''),
        address_bn: blank(values.address_bn ?? ''),
        hotline: company.hotline,
        trade_licence: company.trade_licence,
        bin: company.bin,
        logoUrl: logo?.url ?? null,
        footer_en: blank(values.footer_en ?? ''),
        footer_bn: blank(values.footer_bn ?? ''),
    };

    const options = (
        <fieldset disabled={!editable || saving} className="contents">
            <OptionSection title={t('printInvoice.paperLanguage')}>
                <ChoiceRow
                    label={t('printInvoice.paper')}
                    value={draft.paper}
                    options={[
                        { value: 'a4', label: t('printInvoice.paper.a4') },
                        { value: 'a5', label: t('printInvoice.paper.a5') },
                    ]}
                    onChange={(value) => set('paper', value)}
                />
                <ChoiceRow
                    label={t('printInvoice.language')}
                    value={draft.language}
                    options={[
                        { value: 'en', label: t('printInvoice.language.en'), lang: 'en' },
                        { value: 'bn', label: t('printInvoice.language.bn'), lang: 'bn' },
                        { value: 'both', label: t('printInvoice.language.both') },
                    ]}
                    onChange={(value) => set('language', value)}
                />
                <ChoiceRow
                    label={t('printInvoice.colour')}
                    value={draft.colour}
                    options={[
                        { value: 'brand', label: t('printInvoice.colour.brand') },
                        { value: 'mono', label: t('printInvoice.colour.mono') },
                    ]}
                    onChange={(value) => set('colour', value)}
                />
            </OptionSection>

            <OptionSection title={t('printInvoice.header')} hint={t('printInvoice.headerHint')}>
                <Link to="/settings/business" className={`self-start ${buttonClass('outline', 'sm')}`}>
                    {t('storeSettings.invoice.editBusiness')}
                    <ArrowRightIcon size={16} aria-hidden />
                </Link>
                <div className="flex items-center gap-3">
                    <span className="grid size-12 shrink-0 place-items-center overflow-hidden rounded-sm border border-line-default bg-subtle">
                        {logo?.url ? <img src={logo.url} alt="" className="size-full object-contain" /> : <ImageIcon size={20} aria-hidden className="text-fg-tertiary" />}
                    </span>
                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span className="t-admin-body text-fg-primary">{t('invoiceSettings.logo')}</span>
                        <span className="t-admin-caption text-fg-tertiary">{logo ? t('printInvoice.logoHint') : t('invoiceSettings.logoNone')}</span>
                    </div>
                    <Button variant="outline" size="sm" aria-label={logo ? t('invoiceSettings.logoReplace') : t('invoiceSettings.logoChoose')} onClick={() => setPicking(true)}>
                        {logo ? t('printInvoice.logoChange') : t('printInvoice.logoChoose')}
                    </Button>
                    {logo && (
                        <IconButton aria-label={t('invoiceSettings.logoRemove')} onClick={() => setLogo(null)}>
                            <TrashIcon size={16} aria-hidden />
                        </IconButton>
                    )}
                </div>
                {logoError && (
                    <p className="t-admin-caption text-feedback-danger" role="alert">
                        {logoError}
                    </p>
                )}
                {toggle('show_bin')}
            </OptionSection>

            <OptionSection title={t('printInvoice.store')} hint={t('invoiceSettings.businessHint')}>
                {company.placeholders.length > 0 && (
                    <p className="flex items-start gap-2 rounded-sm bg-subtle px-3.5 py-3 t-admin-caption text-fg-secondary" role="status">
                        <InfoIcon size={16} aria-hidden className="shrink-0 text-fg-brand" />
                        {t('invoiceSettings.placeholders', { list: company.placeholders.map((f) => t(`invoiceSettings.field.${f}` as MessageKey)).join(', ') })}
                    </p>
                )}
                {text('address_en', { required: true })}
                {text('address_bn', { bn: true, required: true })}
                {text('email')}
                {text('website')}
            </OptionSection>

            <OptionSection title={t('printInvoice.shows')}>{toggles.map(toggle)}</OptionSection>

            <OptionSection title={t('printInvoice.footer')} hint={t('printInvoice.footerHint')}>
                {text('footer_en', { label: 'printInvoice.footer_en', area: true })}
                {text('footer_bn', { label: 'printInvoice.footer_bn', area: true, bn: true })}
            </OptionSection>
        </fieldset>
    );

    return (
        <section className="flex min-w-0 flex-col gap-6">
            {editable && <UnsavedChangesGuard when={dirty} />}
            {editable && <StickySaveBar section={t('print.tab.invoice')} dirty={dirty} saved={justSaved} savedLabel={t('printInvoice.saved')} summary={summary} saving={saving} onDiscard={discard} form={FORM_ID} />}
            <PrintTabs />
            <form id={FORM_ID} onSubmit={submit} noValidate className="min-w-0">
                <PrintLayout
                    options={options}
                    preview={
                        <PreviewPanel
                            title={t('printInvoice.preview', { paper: t(`printInvoice.paper.${draft.paper}`), language: t(`printInvoice.preview.${draft.language}`) })}
                            action={
                                <Button variant="outline" size="sm" disabled={opening} onClick={() => void preview()}>
                                    <ArrowSquareOutIcon size={16} aria-hidden />
                                    {t('printInvoice.preview.open')}
                                </Button>
                            }
                        >
                            <InvoicePreview print={draft} store={store} />
                        </PreviewPanel>
                    }
                />
            </form>
            {picking && (
                <MediaPickerModal
                    context={{ title: t('invoiceSettings.logoPicker'), multiple: false, max: 1, kinds: ['image'], defaultFolder: 'pages', ratio: null }}
                    onInsert={insertLogo}
                    onClose={() => setPicking(false)}
                />
            )}
        </section>
    );
}
