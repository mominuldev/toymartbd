import { useEffect } from 'react';
import { useForm, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { InfoIcon } from '@phosphor-icons/react';
import { updateInvoiceSettingsRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Field, inputClass, monoInputClass } from '@/components/ui/Field';
import { FormSection } from '@/components/ui/FormSection';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { useInvoiceSettings, useSaveInvoiceSettings, type InvoiceSettings, type InvoiceSettingsInput } from './api';

const FORM_ID = 'invoice-settings';

const textFields = ['name_en', 'name_bn', 'address_en', 'address_bn', 'trade_licence', 'bin', 'hotline', 'email', 'website', 'footer_en', 'footer_bn'] as const;
type TextField = (typeof textFields)[number];
type FormValues = Record<TextField, string>;

const validateInput = zodResolver(updateInvoiceSettingsRequestSchema);

function toForm(s: InvoiceSettings): FormValues {
    return Object.fromEntries(textFields.map((f) => [f, s[f] ?? ''])) as FormValues;
}

/** The logo is kept as it is (chosen in the media library, not on this form). */
function toInput(v: FormValues, logo: number | null): InvoiceSettingsInput {
    const out: Record<string, string | number | null> = { logo_media_id: logo };
    for (const f of textFields) out[f] = v[f].trim() === '' ? null : v[f].trim();
    return out as InvoiceSettingsInput;
}

/**
 * Settings → Invoice details (P8, PRD N-13): the business identity printed on invoices — name,
 * address (EN + BN), trade licence, BIN, hotline. Follows the "Business" card of Store settings
 * (B · 38 241:50827): ContentHeader with "Save changes", a FormSection with two-column fields.
 * A save affects invoices issued from now on; every issued invoice keeps its own snapshot.
 */
export function InvoiceSettingsPage() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const allowed = can(me, 'settings.view');
    const settings = useInvoiceSettings(allowed);

    if (me && !allowed) return <p className="t-admin-body text-fg-secondary">{t('error.forbidden')}</p>;
    if (settings.isError) return <QueryError error={settings.error} onRetry={() => void settings.refetch()} />;
    if (!settings.data) return <p aria-busy="true" className="sr-only" />;

    return <InvoiceSettingsForm settings={settings.data} editable={can(me, 'settings.update')} />;
}

function InvoiceSettingsForm({ settings, editable }: { settings: InvoiceSettings; editable: boolean }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useSaveInvoiceSettings();
    const { register, handleSubmit, setError, formState, reset } = useForm<FormValues>({
        defaultValues: toForm(settings),
        // Validate the payload the API will receive, against the API's own schema (rule 27).
        resolver: async (values, context, options) => {
            const result = await validateInput(toInput(values, settings.logo_media_id), context, options as never);
            return Object.keys(result.errors).length > 0 ? { values: {}, errors: result.errors as never } : { values, errors: {} };
        },
    });
    useEffect(() => reset(toForm(settings)), [settings, reset]);

    const submit = handleSubmit((values) =>
        save.mutate(toInput(values, settings.logo_media_id), {
            onSuccess: () => notify(t('invoiceSettings.saved')),
            onError: (error) => {
                if (error instanceof ApiError && error.status === 422) {
                    for (const field of textFields) {
                        const message = error.fieldError(field);
                        if (message) setError(field as FieldPath<FormValues>, { message });
                    }
                } else {
                    notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger');
                }
            },
        }),
    );

    const text = (field: TextField, opts: { bn?: boolean; mono?: boolean; hint?: string; required?: boolean } = {}) => (
        <Field label={t(`invoiceSettings.field.${field}` as MessageKey)} hint={opts.hint} error={formState.errors[field]?.message} required={opts.required} optional={!opts.required}>
            {({ id, describedBy, invalid }) => (
                <input
                    id={id}
                    lang={opts.bn ? 'bn' : undefined}
                    aria-describedby={describedBy}
                    aria-invalid={invalid || undefined}
                    className={`h-10 ${opts.mono ? monoInputClass : inputClass}`}
                    {...register(field)}
                />
            )}
        </Field>
    );

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('invoiceSettings.crumb.settings') }, { label: t('invoiceSettings.title') }]}
                title={t('invoiceSettings.title')}
                subtitle={t('invoiceSettings.subtitle')}
                actions={
                    editable && (
                        <Button type="submit" form={FORM_ID} disabled={save.isPending}>
                            {save.isPending ? t('invoiceSettings.saving') : t('invoiceSettings.save')}
                        </Button>
                    )
                }
            />

            {settings.placeholders.length > 0 && (
                <p className="flex max-w-4xl items-start gap-2 rounded-sm bg-subtle px-3.5 py-3 t-admin-caption text-fg-secondary" role="status">
                    <InfoIcon size={16} aria-hidden className="shrink-0 text-fg-brand" />
                    {t('invoiceSettings.placeholders', { list: settings.placeholders.map((f) => t(`invoiceSettings.field.${f}` as MessageKey)).join(', ') })}
                </p>
            )}

            <form id={FORM_ID} onSubmit={submit} noValidate className="flex w-full min-w-0 max-w-4xl flex-col gap-6">
                <fieldset disabled={!editable || save.isPending} className="flex min-w-0 flex-col gap-6">
                    <FormSection title={t('invoiceSettings.business')} description={t('invoiceSettings.businessHint')}>
                        <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                            {text('name_en', { required: true })}
                            {text('name_bn', { bn: true, required: true })}
                            {text('address_en', { required: true })}
                            {text('address_bn', { bn: true, required: true })}
                            {text('trade_licence', { mono: true, required: true, hint: t('invoiceSettings.printed') })}
                            {text('bin', { mono: true, required: true, hint: t('invoiceSettings.printed') })}
                            {text('hotline', { mono: true, required: true })}
                            {text('email')}
                            {text('website')}
                        </div>
                    </FormSection>
                    <FormSection title={t('invoiceSettings.footer')} description={t('invoiceSettings.footerHint')}>
                        <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                            {text('footer_en')}
                            {text('footer_bn', { bn: true })}
                        </div>
                    </FormSection>
                </fieldset>
            </form>
        </section>
    );
}
