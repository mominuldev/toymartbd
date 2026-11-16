import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { ImageSquareIcon, InfoIcon, PaletteIcon, StorefrontIcon } from '@phosphor-icons/react';
import { updateStoreBrandingRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { QueryError } from '@/components/ui/QueryError';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { useSaveStoreBranding, useStoreBranding, type StoreBranding, type StoreBrandingInput } from './brandingApi';
import { BrandColours, BrandingCard, CardNote, CountedField } from './brandingParts';
import { BrandingPreview, type PreviewContent } from './BrandingPreview';
import { BrandingUploadTile, type BrandingImage } from './BrandingUploadTile';
import { StickySaveBar, UnsavedChangesGuard, issuesOf, problemErrors, useChangeSummary, useSavedFlash, useSettingsAccess } from './parts';

const textFields = ['site_name_en', 'site_name_bn', 'tagline_en', 'tagline_bn', 'description_en', 'description_bn'] as const;
type TextField = (typeof textFields)[number];
const imageFields = ['logo', 'logo_dark', 'icon', 'share_image'] as const;
type ImageField = (typeof imageFields)[number];
type FormValues = { [K in TextField]: string } & { [K in ImageField]: BrandingImage | null };

/** The request field each image saves to. */
const idField: Record<ImageField, `${ImageField}_media_id`> = { logo: 'logo_media_id', logo_dark: 'logo_dark_media_id', icon: 'icon_media_id', share_image: 'share_image_media_id' };

/** "What changed" groups for the save bar, in screen order. */
const groups: { key: MessageKey; fields: readonly (keyof FormValues)[] }[] = [
    { key: 'storeBranding.changed.name', fields: ['site_name_en', 'site_name_bn'] },
    { key: 'storeBranding.changed.tagline', fields: ['tagline_en', 'tagline_bn'] },
    { key: 'storeBranding.changed.description', fields: ['description_en', 'description_bn'] },
    { key: 'storeBranding.changed.logo', fields: ['logo'] },
    { key: 'storeBranding.changed.logoDark', fields: ['logo_dark'] },
    { key: 'storeBranding.changed.icon', fields: ['icon'] },
    { key: 'storeBranding.changed.shareImage', fields: ['share_image'] },
];

const FORM_ID = 'store-branding-form';

function toForm(b: StoreBranding): FormValues {
    const v = b.values;
    return {
        site_name_en: v.site_name_en ?? '',
        site_name_bn: v.site_name_bn ?? '',
        tagline_en: v.tagline_en ?? '',
        tagline_bn: v.tagline_bn ?? '',
        description_en: v.description_en ?? '',
        description_bn: v.description_bn ?? '',
        logo: b.media.logo ?? null,
        logo_dark: b.media.logo_dark ?? null,
        icon: b.media.icon ?? null,
        share_image: b.media.share_image ?? null,
    };
}

/** The whole section, blanks as null (which clears the saved value). */
function toInput(v: FormValues): StoreBrandingInput {
    const text = Object.fromEntries(textFields.map((f) => [f, v[f].trim() === '' ? null : v[f].trim()]));
    const ids = Object.fromEntries(imageFields.map((f) => [idField[f], v[f]?.id ?? null]));
    return { ...text, ...ids } as StoreBrandingInput;
}

function same(a: FormValues, b: FormValues, field: keyof FormValues): boolean {
    const x = a[field];
    const y = b[field];
    if (typeof x === 'string' || typeof y === 'string') return x === y;
    return (x?.id ?? null) === (y?.id ?? null);
}

/**
 * Store settings › Site & branding (Figma B · 38 Premium 390:69035, mobile 396:69446): store
 * identity EN/BN, the four brand images and the read-only storefront palette, with a live
 * preview of the tab, the header and a WhatsApp share. The logo is the same setting Taxes &
 * invoices prints. Saves through the sticky save bar.
 */
export function SiteBrandingSection() {
    const { canView, canEdit } = useSettingsAccess();
    const branding = useStoreBranding(canView);

    if (!canView) return null;
    if (branding.isError) return <QueryError error={branding.error} onRetry={() => void branding.refetch()} />;
    if (!branding.data) return <p aria-busy="true" className="sr-only" />;

    return <BrandingForm branding={branding.data} editable={canEdit} />;
}

function BrandingForm({ branding, editable }: { branding: StoreBranding; editable: boolean }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useSaveStoreBranding();
    const [baseline, setBaseline] = useState(() => toForm(branding));
    const [values, setValues] = useState(baseline);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saved, flashSaved] = useSavedFlash();

    const changed = useMemo(() => groups.filter((g) => g.fields.some((f) => !same(values, baseline, f))), [values, baseline]);
    const dirty = changed.length > 0;
    const summary = useChangeSummary(changed.map((g) => t(g.key)));

    // A refetch (another tab saved) replaces the form only while it has no edits.
    useEffect(() => {
        if (dirty) return;
        const next = toForm(branding);
        setBaseline(next);
        setValues(next);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [branding]);

    const set = <K extends keyof FormValues>(field: K, value: FormValues[K]) => {
        setValues((v) => ({ ...v, [field]: value }));
        const key = (imageFields as readonly string[]).includes(field) ? idField[field as ImageField] : field;
        if (errors[key]) setErrors(({ [key]: _gone, ...rest }) => rest);
    };

    const discard = () => {
        setValues(baseline);
        setErrors({});
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const input = toInput(values);
        // Validate the payload the API will receive, against the API's own schema (rule 27).
        const parsed = updateStoreBrandingRequestSchema.safeParse(input);
        if (!parsed.success) {
            setErrors(issuesOf(parsed.error.issues));
            return;
        }
        save.mutate(input, {
            onSuccess: (next) => {
                const form = toForm(next);
                setBaseline(form);
                setValues(form);
                setErrors({});
                flashSaved();
            },
            onError: (error) => {
                const fieldErrors = problemErrors(error);
                if (fieldErrors) setErrors(fieldErrors);
                else notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger');
            },
        });
    };

    const limits = branding.limits;
    const fallback = branding.fallbacks;
    const disabled = !editable || save.isPending;
    const preview: PreviewContent = {
        name: { en: values.site_name_en.trim() || fallback.site_name_en, bn: values.site_name_bn.trim() || fallback.site_name_bn },
        tagline: { en: values.tagline_en.trim(), bn: values.tagline_bn.trim() },
        description: { en: values.description_en.trim(), bn: values.description_bn.trim() },
        logo: values.logo,
        icon: values.icon,
        shareImage: values.share_image,
        host: branding.storefront_host ?? null,
    };

    const text = (field: TextField, label: MessageKey, hint: MessageKey, max: number, opts: { multiline?: boolean } = {}) => (
        <CountedField
            label={t(label)}
            hint={t(hint)}
            value={values[field]}
            max={max}
            onChange={(v) => set(field, v)}
            multiline={opts.multiline}
            bn={field.endsWith('_bn')}
            placeholder={field === 'site_name_en' ? fallback.site_name_en : field === 'site_name_bn' ? fallback.site_name_bn : undefined}
            disabled={disabled}
            error={errors[field]}
        />
    );

    const pair = 'grid gap-3.5 @md:grid-cols-2 @md:gap-4';

    return (
        <form id={FORM_ID} onSubmit={submit} noValidate aria-labelledby="store-branding-title" className="flex min-w-0 flex-col gap-5">
            <header className="flex items-start gap-4">
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <h2 id="store-branding-title" className="t-admin-card-title text-fg-primary">
                        {t('storeBranding.title')}
                    </h2>
                    <p className="t-admin-body text-fg-secondary">
                        <span className="md:hidden">{t('storeBranding.descriptionShort')}</span>
                        <span className="hidden md:inline">{t('storeBranding.description')}</span>
                    </p>
                </div>
                <span className="hidden shrink-0 md:inline-flex">
                    <StatusChip tone="success" label={t('storeBranding.live')} />
                </span>
            </header>

            <div className="@container">
                <div className="grid gap-4 @3xl:grid-cols-[minmax(0,1fr)_20rem] @3xl:gap-x-6 @3xl:gap-y-5">
                    <BrandingCard icon={StorefrontIcon} tone="brand" title={t('storeBranding.identity.title')} description={t('storeBranding.identity.description')} className="@container @3xl:col-start-1">
                        <div className={pair}>
                            {text('site_name_en', 'storeBranding.field.site_name_en', 'storeBranding.hint.site_name_en', limits.site_name)}
                            {text('site_name_bn', 'storeBranding.field.site_name_bn', 'storeBranding.hint.site_name_bn', limits.site_name)}
                        </div>
                        <div className={pair}>
                            {text('tagline_en', 'storeBranding.field.tagline_en', 'storeBranding.hint.tagline_en', limits.tagline)}
                            {text('tagline_bn', 'storeBranding.field.tagline_bn', 'storeBranding.hint.tagline_bn', limits.tagline)}
                        </div>
                        <div className={pair}>
                            {text('description_en', 'storeBranding.field.description_en', 'storeBranding.hint.description_en', limits.description, { multiline: true })}
                            {text('description_bn', 'storeBranding.field.description_bn', 'storeBranding.hint.description_bn', limits.description, { multiline: true })}
                        </div>
                    </BrandingCard>

                    <BrandingCard icon={ImageSquareIcon} tone="info" title={t('storeBranding.images.title')} description={t('storeBranding.images.description')} className="@container @3xl:col-start-1">
                        <div className="grid gap-4 @md:grid-cols-2">
                            <BrandingUploadTile
                                title={t('storeBranding.logo.title')}
                                hint={t('storeBranding.logo.hint')}
                                emptyHint={t('storeBranding.logo.empty')}
                                pickerTitle={t('storeBranding.logo.picker')}
                                value={values.logo}
                                onChange={(v) => set('logo', v)}
                                disabled={disabled}
                                error={errors.logo_media_id}
                                renderPreview={(img) => (
                                    <div className="grid h-29 place-items-center rounded-xs bg-subtle p-4">
                                        {img.url && <img src={img.url} alt="" className="max-h-full max-w-full object-contain" />}
                                    </div>
                                )}
                            />
                            <BrandingUploadTile
                                title={t('storeBranding.logoDark.title')}
                                hint={t('storeBranding.logoDark.hint')}
                                emptyHint={t('storeBranding.logoDark.empty')}
                                pickerTitle={t('storeBranding.logoDark.picker')}
                                optional
                                value={values.logo_dark}
                                onChange={(v) => set('logo_dark', v)}
                                disabled={disabled}
                                error={errors.logo_dark_media_id}
                                renderPreview={(img) => (
                                    <div data-theme="admin-dark" className="grid h-29 place-items-center rounded-xs bg-canvas p-4">
                                        {img.url && <img src={img.url} alt="" className="max-h-full max-w-full object-contain" />}
                                    </div>
                                )}
                            />
                            <BrandingUploadTile
                                title={t('storeBranding.icon.title')}
                                hint={t('storeBranding.icon.hint')}
                                emptyHint={t('storeBranding.icon.empty')}
                                pickerTitle={t('storeBranding.icon.picker')}
                                ratio={[1, 1]}
                                minSize={512}
                                value={values.icon}
                                onChange={(v) => set('icon', v)}
                                disabled={disabled}
                                error={errors.icon_media_id}
                                renderPreview={(img) => (
                                    <div className="flex h-29 items-end justify-center gap-4 rounded-xs bg-subtle pb-3.5">
                                        {(
                                            [
                                                [64, 'size-16 rounded-md'],
                                                [40, 'size-10 rounded-sm'],
                                                [24, 'size-6 rounded-xs'],
                                                [16, 'size-4 rounded-xs'],
                                            ] as const
                                        ).map(([px, cls]) => (
                                            <span key={px} className="flex flex-col items-center gap-1.5">
                                                {img.url ? <img src={img.url} alt="" className={`${cls} object-cover`} /> : <span className={`${cls} bg-brand`} />}
                                                <span className="t-admin-mono-sm text-fg-tertiary">{px}</span>
                                            </span>
                                        ))}
                                    </div>
                                )}
                            />
                            <BrandingUploadTile
                                title={t('storeBranding.share.title')}
                                hint={t('storeBranding.share.hint')}
                                emptyHint={t('storeBranding.share.empty')}
                                pickerTitle={t('storeBranding.share.picker')}
                                ratio={[1200, 630]}
                                value={values.share_image}
                                onChange={(v) => set('share_image', v)}
                                disabled={disabled}
                                error={errors.share_image_media_id}
                                renderPreview={(img) => (
                                    <div className="h-29 overflow-hidden rounded-xs bg-tile-sky">{img.url && <img src={img.url} alt="" className="size-full object-cover" />}</div>
                                )}
                            />
                        </div>
                        <CardNote icon={InfoIcon}>{t('storeBranding.images.note')}</CardNote>
                    </BrandingCard>

                    <BrandingPreview content={preview} className="@3xl:sticky @3xl:top-6 @3xl:col-start-2 @3xl:row-span-3 @3xl:row-start-1 @3xl:self-start" />

                    <BrandColours icon={PaletteIcon} className="@3xl:col-start-1" />
                </div>
            </div>

            {editable && <UnsavedChangesGuard when={dirty} />}
            {editable && (
                <StickySaveBar section={t('storeBranding.title')} dirty={dirty} saved={saved} summary={summary} saving={save.isPending} onDiscard={discard} form={FORM_ID} />
            )}
        </form>
    );
}
