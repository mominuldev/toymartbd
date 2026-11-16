import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { MessengerLogoIcon, PhoneIcon, WhatsappLogoIcon } from '@phosphor-icons/react';
import { updateBusinessDetailsRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Field, inputClass } from '@/components/ui/Field';
import { FormSection } from '@/components/ui/FormSection';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { useBusinessDetails, useSaveBusinessDetails, type BusinessDetails, type BusinessDetailsInput } from './api';
import { Note, StickySaveBar, UnsavedChangesGuard, formatMobile, useChangeSummary, useSavedFlash, useSettingsAccess } from './parts';

const FORM_ID = 'business-details-form';

const fields = ['name_en', 'name_bn', 'trade_licence', 'bin', 'hotline', 'whatsapp', 'messenger'] as const;
type FieldName = (typeof fields)[number];
type FormValues = Record<FieldName, string>;

const validateInput = zodResolver(updateBusinessDetailsRequestSchema);

function toForm(b: BusinessDetails): FormValues {
    return { name_en: b.name_en ?? '', name_bn: b.name_bn ?? '', trade_licence: b.trade_licence ?? '', bin: b.bin ?? '', hotline: b.hotline ?? '', whatsapp: formatMobile(b.whatsapp), messenger: b.messenger ?? '' };
}

/** Blank → null, which removes the saved value (an invoice then prints "[placeholder]"). */
function toInput(v: FormValues): BusinessDetailsInput {
    return Object.fromEntries(fields.map((f) => [f, v[f].trim() === '' ? null : v[f].trim()])) as BusinessDetailsInput;
}

/**
 * Store settings › Business (Figma B · 38 241:50827): store name EN/BN, trade licence, BIN,
 * hotline, WhatsApp and the Messenger Page (the storefront's m.me chat link). One value for the store and invoices — the name, licence, BIN and
 * hotline are the same settings Taxes & invoices prints (CompanyDetails); the server keeps
 * them in one place. Values are placeholders until the owner supplies real ones.
 */
export function BusinessSection() {
    const { canView, canEdit } = useSettingsAccess();
    const business = useBusinessDetails(canView);

    if (!canView) return null;
    if (business.isError) return <QueryError error={business.error} onRetry={() => void business.refetch()} />;
    if (!business.data) return <p aria-busy="true" className="sr-only" />;

    return <BusinessForm business={business.data} editable={canEdit} />;
}

function BusinessForm({ business, editable }: { business: BusinessDetails; editable: boolean }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useSaveBusinessDetails();
    const { register, handleSubmit, setError, formState, reset } = useForm<FormValues>({
        defaultValues: toForm(business),
        // Validate the payload the API will receive, against the API's own schema (rule 27).
        resolver: async (values, context, options) => {
            const result = await validateInput(toInput(values), context, options as never);
            return Object.keys(result.errors).length > 0 ? { values: {}, errors: result.errors as never } : { values, errors: {} };
        },
    });
    useEffect(() => reset(toForm(business)), [business, reset]);
    const [justSaved, flashSaved] = useSavedFlash();
    const label = (f: FieldName) => t(`storeSettings.business.field.${f}` as MessageKey);
    const changed = fields.filter((f) => formState.dirtyFields[f]);
    const dirty = changed.length > 0;
    const summary = useChangeSummary(changed.map(label));

    const submit = handleSubmit((values) =>
        save.mutate(toInput(values), {
            onSuccess: (saved) => {
                // Show what the server stored (WhatsApp normalised to E.164), even when the cache kept the same object.
                reset(toForm(saved));
                flashSaved();
            },
            onError: (error) => {
                if (error instanceof ApiError && error.status === 422) {
                    for (const field of fields) {
                        const message = error.fieldError(field);
                        if (message) setError(field, { message });
                    }
                } else {
                    notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger');
                }
            },
        }),
    );

    const placeholder: Partial<Record<FieldName, string>> = {
        name_en: business.default_names.name_en,
        name_bn: business.default_names.name_bn,
        trade_licence: t('storeSettings.business.placeholder.trade_licence'),
        bin: t('storeSettings.business.placeholder.bin'),
        hotline: t('storeSettings.business.placeholder.hotline'),
        whatsapp: t('storeSettings.business.placeholder.whatsapp'),
        messenger: t('storeSettings.business.placeholder.messenger'),
    };
    const hint: Partial<Record<FieldName, string>> = {
        trade_licence: t('storeSettings.business.printed'),
        hotline:
            business.hotline === null && business.hotline_default
                ? t('storeSettings.business.hotlineFallback', { number: business.hotline_default })
                : t('storeSettings.business.shown'),
        messenger: t('storeSettings.business.messengerHint'),
    };

    const text = (f: FieldName, opts: { bn?: boolean; type?: string } = {}) => (
        <Field label={label(f)} hint={hint[f]} error={formState.errors[f]?.message}>
            {({ id, describedBy, invalid }) => (
                <input
                    id={id}
                    type={opts.type ?? 'text'}
                    lang={opts.bn ? 'bn' : undefined}
                    aria-describedby={describedBy}
                    aria-invalid={invalid || undefined}
                    placeholder={placeholder[f]}
                    className={`h-10 ${inputClass}`}
                    {...register(f)}
                />
            )}
        </Field>
    );

    const phone = (f: 'hotline' | 'whatsapp') => (
        <Field label={label(f)} hint={hint[f]} error={formState.errors[f]?.message}>
            {({ id, describedBy, invalid }) => (
                <Input
                    id={id}
                    type="tel"
                    inputMode="tel"
                    autoComplete="off"
                    leading={f === 'hotline' ? <PhoneIcon size={16} aria-hidden /> : <WhatsappLogoIcon size={16} aria-hidden />}
                    aria-describedby={describedBy}
                    aria-invalid={invalid || undefined}
                    placeholder={placeholder[f]}
                    {...register(f)}
                />
            )}
        </Field>
    );

    return (
        <form id={FORM_ID} onSubmit={submit} noValidate aria-label={t('storeSettings.business.title')} className="flex min-w-0 flex-col">
            {editable && <UnsavedChangesGuard when={dirty} />}
            {editable && (
                <StickySaveBar
                    section={t('storeSettings.nav.business')}
                    dirty={dirty}
                    saved={justSaved} savedLabel={t('storeSettings.business.saved')}
                    summary={summary}
                    saving={save.isPending}
                    onDiscard={() => reset(toForm(business))}
                    form={FORM_ID}
                />
            )}
            <FormSection title={t('storeSettings.business.title')}>
                {business.placeholders.length > 0 && <Note>{t('storeSettings.business.placeholders', { list: business.placeholders.map((f) => label(f as FieldName)).join(', ') })}</Note>}
                <fieldset disabled={!editable || save.isPending} className="grid min-w-0 gap-x-4 gap-y-3.5 md:grid-cols-2">
                    {text('name_en')}
                    {text('name_bn', { bn: true })}
                    {text('trade_licence')}
                    {text('bin')}
                    {phone('hotline')}
                    {phone('whatsapp')}
                    <Field label={label('messenger')} hint={hint.messenger} error={formState.errors.messenger?.message}>
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                type="text"
                                autoComplete="off"
                                autoCapitalize="none"
                                spellCheck={false}
                                leading={<MessengerLogoIcon size={16} aria-hidden />}
                                aria-describedby={describedBy}
                                aria-invalid={invalid || undefined}
                                placeholder={placeholder.messenger}
                                {...register('messenger')}
                            />
                        )}
                    </Field>
                </fieldset>
            </FormSection>
        </form>
    );
}
