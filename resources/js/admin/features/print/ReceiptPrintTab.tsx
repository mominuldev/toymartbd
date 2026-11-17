import { useEffect, useState, type FormEvent } from 'react';
import { PrinterIcon, StorefrontIcon } from '@phosphor-icons/react';
import { updatePrintSettingsRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { translate, useI18n, type Locale } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { ReceiptBody, ReceiptPrint } from '@/features/pos/Receipt';
import type { PosReceipt } from '@/features/pos/api';
import { StickySaveBar, UnsavedChangesGuard, useChangeSummary, useSavedFlash, useSettingsAccess } from '@/features/storeSettings/parts';
import { usePrintSettings, useSavePrintSettings, type ReceiptPrint as ReceiptSettings } from './api';
import { ChoiceRow, OptionSection, PreviewPanel, PrintLayout, PrintTabs, ToggleRow } from './parts';
import { SAMPLE_RECEIPT } from './sampleReceipt';

const FORM_ID = 'print-receipt-form';
const FOOTER_MAX = 160;

type Toggle = 'auto_print' | 'show_logo' | 'show_branch' | 'show_bin' | 'show_cashier' | 'show_customer_name' | 'show_sku' | 'show_barcode' | 'show_whatsapp_qr' | 'show_cut_line';

/** Which save-bar part each field belongs to. */
const GROUPS = {
    printer: ['paper', 'language', 'text_size', 'auto_print'],
    header: ['show_logo', 'show_branch', 'show_bin'],
    shows: ['show_cashier', 'show_customer_name', 'show_sku', 'show_barcode', 'show_whatsapp_qr', 'show_cut_line'],
    footer: ['footer_en', 'footer_bn'],
} as const satisfies Record<string, readonly (keyof ReceiptSettings)[]>;

/** Empty or blank footer text is "no footer" (the standard thank-you). */
const footerValue = (text: string | null) => (text && text.trim() !== '' ? text.trim() : null);

/** The settings as the server will store them — what is compared for "unsaved" and sent on save. */
function normalise(draft: ReceiptSettings): ReceiptSettings {
    return { ...draft, footer_en: footerValue(draft.footer_en), footer_bn: footerValue(draft.footer_bn) };
}

/**
 * Settings › Invoices, receipts & labels › Thermal receipt (Figma 529:81638): the roll width,
 * language, text size and auto-print, what the header and body show, and the bottom message —
 * one PUT `{receipt}` through the sticky save bar. The preview prints a sample sale (static
 * figures) at the chosen roll width, live, with the same component the register prints; "Print
 * test receipt" sends it to the browser's printer with the draft settings. Read with
 * settings.view, change with settings.update (the settings layout says when it is read-only).
 */
export function ReceiptPrintTab() {
    const { t } = useI18n();
    const { me, canView, canEdit } = useSettingsAccess();
    const settings = usePrintSettings(canView);

    let body;
    if (me && !canView) body = <p className="t-admin-body text-fg-secondary">{t('error.forbidden')}</p>;
    else if (settings.isError) body = <QueryError error={settings.error} onRetry={() => void settings.refetch()} />;
    else if (!settings.data) body = <p aria-busy="true" className="sr-only" />;
    else body = <ReceiptForm saved={settings.data.receipt} editable={canEdit} />;

    return (
        <section className="flex min-w-0 flex-col gap-6">
            <PrintTabs />
            {body}
        </section>
    );
}

function ReceiptForm({ saved, editable }: { saved: ReceiptSettings; editable: boolean }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const save = useSavePrintSettings();
    const [draft, setDraft] = useState<ReceiptSettings>(saved);
    const [errors, setErrors] = useState<{ footer_en?: string; footer_bn?: string }>({});
    const [printing, setPrinting] = useState(false);
    const [justSaved, flashSaved] = useSavedFlash();

    useEffect(() => setDraft(saved), [saved]);

    const current = normalise(draft);
    const changed = (fields: readonly (keyof ReceiptSettings)[]) => fields.some((f) => current[f] !== saved[f]);
    const parts = (Object.keys(GROUPS) as (keyof typeof GROUPS)[]).filter((g) => changed(GROUPS[g]));
    const dirty = parts.length > 0;
    const summary = useChangeSummary(parts.map((g) => t(`printReceipt.changed.${g}`)));

    const set = <K extends keyof ReceiptSettings>(key: K, value: ReceiptSettings[K]) => {
        if (editable) setDraft((d) => ({ ...d, [key]: value }));
    };
    const toggle = (key: Toggle, label: string, hint?: string) => (
        <ToggleRow label={label} hint={hint} checked={draft[key]} disabled={!editable} onChange={(v) => set(key, v)} />
    );

    const discard = () => {
        setDraft(saved);
        setErrors({});
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const input = { receipt: current };
        const parsed = updatePrintSettingsRequestSchema.safeParse(input);
        const local = {
            footer_en: (current.footer_en ?? '').length > FOOTER_MAX ? t('printReceipt.footer.tooLong') : undefined,
            footer_bn: (current.footer_bn ?? '').length > FOOTER_MAX ? t('printReceipt.footer.tooLong') : undefined,
        };
        if (!parsed.success || local.footer_en || local.footer_bn) {
            setErrors(local);
            return;
        }
        setErrors({});
        save.mutate(parsed.data, {
            onSuccess: () => flashSaved(),
            onError: (e) => {
                if (e instanceof ApiError && e.status === 422) setErrors({ footer_en: e.fieldError('receipt.footer_en'), footer_bn: e.fieldError('receipt.footer_bn') });
                else notify(e instanceof ApiError ? e.problem.title : t('error.title'), 'danger');
            },
        });
    };

    // The preview: the sample sale with the draft settings, in the language it would print in.
    const sample: PosReceipt = { ...SAMPLE_RECEIPT, print: current };
    const language: Locale = draft.language === 'ask' ? locale : draft.language;
    const logo = (
        <span aria-hidden className="grid size-8 place-items-center rounded-xs bg-fg-primary text-fg-inverse">
            <StorefrontIcon size={18} weight="fill" />
        </span>
    );

    const options = (
        <fieldset disabled={!editable} className="contents">
            <OptionSection title={t('printReceipt.printer.title')} hint={t('printReceipt.printer.hint')}>
                <ChoiceRow
                    label={t('printReceipt.width.label')}
                    value={draft.paper}
                    options={(['80', '58'] as const).map((paper) => ({ value: paper, label: t('printReceipt.width.mm', { paper }) }))}
                    onChange={(v) => set('paper', v)}
                />
                <ChoiceRow
                    label={t('printReceipt.language.label')}
                    value={draft.language}
                    options={[
                        { value: 'en', label: 'English', lang: 'en' },
                        { value: 'bn', label: 'বাংলা', lang: 'bn' },
                        { value: 'ask', label: t('printReceipt.language.ask') },
                    ]}
                    onChange={(v) => set('language', v)}
                />
                <ChoiceRow
                    label={t('printReceipt.textSize.label')}
                    value={draft.text_size}
                    options={[
                        { value: 'normal', label: t('printReceipt.textSize.normal') },
                        { value: 'large', label: t('printReceipt.textSize.large') },
                    ]}
                    onChange={(v) => set('text_size', v)}
                />
                {toggle('auto_print', t('printReceipt.autoPrint.label'), t('printReceipt.autoPrint.hint'))}
            </OptionSection>
            <OptionSection title={t('printReceipt.header.title')}>
                {toggle('show_logo', t('printReceipt.header.logo'))}
                {toggle('show_branch', t('printReceipt.header.branch'))}
                {toggle('show_bin', t('printReceipt.header.bin'))}
            </OptionSection>
            <OptionSection title={t('printReceipt.shows.title')}>
                {toggle('show_cashier', t('printReceipt.shows.cashier'))}
                {toggle('show_customer_name', t('printReceipt.shows.customer'), t('printReceipt.shows.customerHint'))}
                {toggle('show_sku', t('printReceipt.shows.sku'))}
                {toggle('show_barcode', t('printReceipt.shows.barcode'), t('printReceipt.shows.barcodeHint'))}
                {toggle('show_whatsapp_qr', t('printReceipt.shows.whatsapp'))}
                {toggle('show_cut_line', t('printReceipt.shows.cut'))}
            </OptionSection>
            <OptionSection title={t('printReceipt.footer.title')} hint={t('printReceipt.footer.hint')}>
                {(['footer_en', 'footer_bn'] as const).map((key) => (
                    <Field key={key} label={t(key === 'footer_en' ? 'printReceipt.footer.en' : 'printReceipt.footer.bn')} error={errors[key]}>
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                aria-describedby={describedBy}
                                aria-invalid={invalid}
                                lang={key === 'footer_bn' ? 'bn' : 'en'}
                                maxLength={FOOTER_MAX}
                                disabled={!editable || save.isPending}
                                placeholder={translate(key === 'footer_bn' ? 'bn' : 'en', 'pos.receipt.thanks')}
                                value={draft[key] ?? ''}
                                onChange={(e) => set(key, e.target.value)}
                            />
                        )}
                    </Field>
                ))}
            </OptionSection>
        </fieldset>
    );

    const preview = (
        <PreviewPanel
            title={t('printReceipt.preview.title')}
            action={
                <Button type="button" variant="ghost" size="sm" onClick={() => setPrinting(true)} disabled={printing}>
                    <PrinterIcon size={16} aria-hidden />
                    {t('printReceipt.preview.print')}
                </Button>
            }
        >
            <div className="flex flex-col items-start gap-2">
                <p className="t-admin-caption text-fg-secondary">{t('printReceipt.width.mm', { paper: draft.paper })}</p>
                <div className="rounded-xs bg-surface shadow-e2">
                    <ReceiptBody receipt={sample} language={language} logo={logo} />
                </div>
            </div>
            {printing && <ReceiptPrint receipt={sample} language={language} logo={logo} onDone={() => setPrinting(false)} />}
        </PreviewPanel>
    );

    return (
        <form id={FORM_ID} onSubmit={submit} noValidate aria-label={t('printReceipt.form')}>
            {editable && <UnsavedChangesGuard when={dirty} />}
            {editable && (
                <StickySaveBar section={t('print.tab.receipt')} dirty={dirty} saved={justSaved} savedLabel={t('printReceipt.saved')} summary={summary} saving={save.isPending} onDiscard={discard} form={FORM_ID} />
            )}
            <PrintLayout options={options} preview={preview} />
        </form>
    );
}
