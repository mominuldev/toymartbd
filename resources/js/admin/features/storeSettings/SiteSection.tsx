import { useEffect, useId, useMemo, useState, type ComponentType, type FormEvent, type ReactNode } from 'react';
import {
    ArrowsClockwiseIcon,
    BrowserIcon,
    CloudArrowUpIcon,
    ImageSquareIcon,
    ImagesIcon,
    InfoIcon,
    LockSimpleIcon,
    MagnifyingGlassIcon,
    PaletteIcon,
    ShoppingBagOpenIcon,
    StorefrontIcon,
    TrashIcon,
    type IconProps,
} from '@phosphor-icons/react';
import { updateSiteBrandingRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { Field, inputClass } from '@/components/ui/Field';
import { IconButton } from '@/components/ui/IconButton';
import { QueryError } from '@/components/ui/QueryError';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import type { Media } from '@/features/media';
import { MediaPickerModal, type InsertFailure } from '@/features/media/picker';
import { useSettingsAccess } from './parts';
import { useSaveSiteBranding, useSiteBranding, type SiteBranding, type SiteBrandingInput, type SiteImage } from './siteApi';

const textFields = ['name', 'name_bn', 'tagline', 'tagline_bn', 'description', 'description_bn'] as const;
type TextField = (typeof textFields)[number];
const imageFields = ['logo', 'logo_dark', 'icon', 'social_image'] as const;
type ImageField = (typeof imageFields)[number];
/** The request's media id key for each image field. */
const idKey = { logo: 'logo_media_id', logo_dark: 'logo_dark_media_id', icon: 'icon_media_id', social_image: 'social_image_media_id' } as const satisfies Record<ImageField, keyof SiteBrandingInput>;

type Form = { text: Record<TextField, string>; images: Record<ImageField, SiteImage | null> };

/** The shape rules the server enforces (UpdateSiteBrandingRequest), repeated so the picker can say why — display only. */
export const ICON_MIN = 512;
const LOGO_MIN_WIDTH = 120;
const SOCIAL_MIN_WIDTH = 600;

export function imageProblem(field: ImageField, width: number | null, height: number | null): MessageKey | null {
    if (!width || !height) return 'siteSettings.image.notReady';
    if (field === 'icon') return Math.min(width, height) >= ICON_MIN && Math.abs(width - height) <= Math.max(1, Math.round(width * 0.01)) ? null : 'siteSettings.image.iconShape';
    if (field === 'social_image') {
        const ratio = width / height;
        return width >= SOCIAL_MIN_WIDTH && ratio >= 1.8 && ratio <= 2 ? null : 'siteSettings.image.socialShape';
    }
    return width >= LOGO_MIN_WIDTH ? null : 'siteSettings.image.logoSmall';
}

function toForm(s: SiteBranding): Form {
    return {
        text: Object.fromEntries(textFields.map((f) => [f, s[f] ?? ''])) as Record<TextField, string>,
        images: { logo: s.logo, logo_dark: s.logo_dark, icon: s.icon, social_image: s.social_image },
    };
}

/** Blank → null, which clears the saved value (the storefront then falls back). */
function toInput(f: Form): SiteBrandingInput {
    const input: Record<string, string | number | null> = {};
    for (const field of textFields) input[field] = f.text[field].trim() === '' ? null : f.text[field].trim();
    for (const field of imageFields) input[idKey[field]] = f.images[field]?.id ?? null;
    return input as SiteBrandingInput;
}

/** Characters as the server counts them (code points), so the counter and `max:` agree. */
const length = (value: string) => [...value].length;

/**
 * Store settings › Site & branding (Figma B · 38 Store settings · Premium): how the storefront
 * introduces itself — name, tagline and description in English and Bangla, the logo and an
 * optional dark-background logo, the square store icon the favicons are made from and the share
 * image — with a live preview of the browser tab, the header and a shared link. Brand colours
 * come from the design tokens and are shown read-only. Read with settings.view, save with
 * settings.update; one PUT for the whole section.
 */
export function SiteSection() {
    const { canView, canEdit } = useSettingsAccess();
    const site = useSiteBranding(canView);

    if (!canView) return null;
    if (site.isError) return <QueryError error={site.error} onRetry={() => void site.refetch()} />;
    if (!site.data) return <p aria-busy="true" className="sr-only" />;

    return <SiteForm site={site.data} editable={canEdit} />;
}

function SiteForm({ site, editable }: { site: SiteBranding; editable: boolean }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useSaveSiteBranding();
    const initial = useMemo(() => toForm(site), [site]);
    const [form, setForm] = useState<Form>(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    useEffect(() => setForm(initial), [initial]);

    const dirty = JSON.stringify(toInput(form)) !== JSON.stringify(toInput(initial));
    const setText = (field: TextField, value: string) => setForm((f) => ({ ...f, text: { ...f.text, [field]: value } }));
    const setImage = (field: ImageField, value: SiteImage | null) => setForm((f) => ({ ...f, images: { ...f.images, [field]: value } }));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const input = toInput(form);
        // The API's own schema (rule 27); the server stays the authority on images.
        const parsed = updateSiteBrandingRequestSchema.safeParse(input);
        if (!parsed.success) {
            const next: Record<string, string> = {};
            for (const issue of parsed.error.issues) next[String(issue.path[0])] ??= issue.message;
            setErrors(next);
            return;
        }
        setErrors({});
        save.mutate(parsed.data, {
            onSuccess: () => notify(t('siteSettings.saved')),
            onError: (error) => {
                if (error instanceof ApiError && error.status === 422) {
                    const next: Record<string, string> = {};
                    for (const { field, message } of error.problem.errors ?? []) next[field] ??= message;
                    setErrors(next);
                } else {
                    notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger');
                }
            },
        });
    };

    const text = (field: TextField, opts: { area?: boolean; hint: string }) => {
        const bn = field.endsWith('_bn');
        const max = site.max_lengths[field];
        const value = form.text[field];
        const placeholder = field === 'name' ? site.fallback_names.name : field === 'name_bn' ? site.fallback_names.name_bn : undefined;
        return (
            <Field label={t(`siteSettings.field.${field}` as MessageKey)} error={errors[field]}>
                {({ id, describedBy, invalid }) => (
                    <>
                        {opts.area ? (
                            <textarea
                                id={id}
                                lang={bn ? 'bn' : undefined}
                                rows={3}
                                aria-describedby={`${id}-meta${describedBy ? ` ${describedBy}` : ''}`}
                                aria-invalid={invalid || undefined}
                                value={value}
                                onChange={(e) => setText(field, e.target.value)}
                                className={`min-h-22 resize-y py-2.5 ${inputClass}`}
                            />
                        ) : (
                            <input
                                id={id}
                                type="text"
                                lang={bn ? 'bn' : undefined}
                                aria-describedby={`${id}-meta${describedBy ? ` ${describedBy}` : ''}`}
                                aria-invalid={invalid || undefined}
                                placeholder={placeholder}
                                value={value}
                                onChange={(e) => setText(field, e.target.value)}
                                className={`h-10 ${inputClass}`}
                            />
                        )}
                        <span id={`${id}-meta`} className="flex items-baseline gap-2 t-admin-caption text-fg-secondary">
                            <span className="min-w-0 flex-1">{opts.hint}</span>
                            <span className={`shrink-0 t-admin-mono-sm ${length(value) > max ? 'text-fg-primary' : 'text-fg-tertiary'}`}>
                                {t('siteSettings.counter', { count: length(value), max })}
                            </span>
                        </span>
                    </>
                )}
            </Field>
        );
    };

    return (
        <form onSubmit={submit} noValidate aria-labelledby="site-settings-title" className="flex min-w-0 flex-col gap-5">
            <div className="flex flex-wrap items-start gap-4">
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <h2 id="site-settings-title" className="t-admin-card-title text-fg-primary">
                        {t('siteSettings.title')}
                    </h2>
                    <p className="t-admin-body text-fg-secondary">{t('siteSettings.description')}</p>
                </div>
                <StatusChip tone="success" label={t('siteSettings.live')} />
            </div>

            <div className="flex flex-col gap-6 xl:flex-row xl:items-start">
                <fieldset disabled={!editable || save.isPending} className="flex min-w-0 flex-1 flex-col gap-5">
                    <Card icon={StorefrontIcon} tile="bg-brand-subtle text-fg-brand" title={t('siteSettings.identity.title')} description={t('siteSettings.identity.description')}>
                        <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                            {text('name', { hint: t('siteSettings.hint.name') })}
                            {text('name_bn', { hint: t('siteSettings.hint.nameBn') })}
                            {text('tagline', { hint: t('siteSettings.hint.tagline') })}
                            {text('tagline_bn', { hint: t('siteSettings.hint.taglineBn') })}
                            {text('description', { area: true, hint: t('siteSettings.hint.description') })}
                            {text('description_bn', { area: true, hint: t('siteSettings.hint.descriptionBn') })}
                        </div>
                    </Card>

                    <Card icon={ImageSquareIcon} tile="bg-info-subtle text-feedback-info" title={t('siteSettings.images.title')} description={t('siteSettings.images.description')}>
                        <div className="grid gap-4 md:grid-cols-2">
                            {imageFields.map((field) => (
                                <ImageTile
                                    key={field}
                                    field={field}
                                    image={form.images[field]}
                                    onChange={(next) => setImage(field, next)}
                                    editable={editable && !save.isPending}
                                    error={errors[idKey[field]]}
                                />
                            ))}
                        </div>
                        <p className="flex items-start gap-2 rounded-xs bg-subtle px-3 py-2.5 t-admin-caption text-fg-secondary">
                            <InfoIcon size={16} aria-hidden className="shrink-0 text-fg-brand" />
                            <span>{t('siteSettings.images.note')}</span>
                        </p>
                    </Card>

                    <BrandColours />
                </fieldset>

                <LivePreview form={form} site={site} />
            </div>

            {editable && (
                <div className="sticky bottom-0 z-10 flex flex-wrap items-center gap-3 rounded-md border border-line-default bg-surface px-4 py-3 shadow-e4">
                    <p className="flex min-w-0 flex-1 items-center gap-2 t-admin-body text-fg-primary" role="status">
                        <span aria-hidden className={`size-2.5 shrink-0 rounded-full ${dirty ? 'bg-brand' : 'bg-line-strong'}`} />
                        {dirty ? t('siteSettings.bar.dirty') : t('siteSettings.bar.clean')}
                    </p>
                    <Button variant="ghost" disabled={!dirty || save.isPending} onClick={() => (setForm(initial), setErrors({}))}>
                        {t('common.discard')}
                    </Button>
                    <Button type="submit" disabled={!dirty || save.isPending}>
                        {save.isPending ? t('storeSettings.saving') : t('siteSettings.bar.save')}
                    </Button>
                </div>
            )}
        </form>
    );
}

/** A settings card with a tinted icon tile (the premium pass: no left-edge accents). */
function Card({ icon: Icon, tile, title, description, aside, children }: { icon: ComponentType<IconProps>; tile: string; title: string; description: string; aside?: ReactNode; children: ReactNode }) {
    const titleId = useId();
    return (
        <section aria-labelledby={titleId} className="flex min-w-0 flex-col gap-4.5 rounded-md border border-line-default bg-surface p-5 shadow-e1">
            <div className="flex items-start gap-3">
                <span aria-hidden className={`grid size-9 shrink-0 place-items-center rounded-sm ${tile}`}>
                    <Icon size={18} />
                </span>
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <h3 id={titleId} className="t-admin-section text-fg-primary">
                        {title}
                    </h3>
                    <p className="t-admin-caption text-fg-secondary">{description}</p>
                </div>
                {aside}
            </div>
            {children}
        </section>
    );
}

/** The shape hint each image tile and its picker show. */
const specKey: Record<ImageField, MessageKey> = {
    logo: 'siteSettings.spec.logo',
    logo_dark: 'siteSettings.spec.logoDark',
    icon: 'siteSettings.spec.icon',
    social_image: 'siteSettings.spec.social',
};

function ImageTile({ field, image, onChange, editable, error }: { field: ImageField; image: SiteImage | null; onChange: (next: SiteImage | null) => void; editable: boolean; error?: string }) {
    const { t } = useI18n();
    const [picking, setPicking] = useState<'library' | 'upload' | null>(null);
    const title = t(`siteSettings.image.${field}` as MessageKey);

    const insert = async (picked: Media[]): Promise<InsertFailure[]> => {
        const media = picked[0];
        if (!media) return [];
        if (media.processing_status !== 'ready') return [{ media, reason: t('siteSettings.image.notReady') }];
        const problem = imageProblem(field, media.width, media.height);
        if (problem) return [{ media, reason: t(problem) }];
        onChange({ id: media.id, url: media.urls.medium ?? media.urls.thumb, width: media.width, height: media.height, filename: media.filename });
        setPicking(null);
        return [];
    };

    return (
        <div className="flex min-w-0 flex-col gap-3 rounded-sm border border-line-default p-3">
            <div className="flex min-h-8 items-center gap-1.5">
                <h4 className="min-w-0 flex-1 t-admin-body-strong text-fg-primary">{title}</h4>
                {field === 'logo_dark' && <span className="rounded-full bg-subtle px-1.5 t-admin-caption-strong text-fg-secondary">{t('common.optional')}</span>}
                {image && editable && (
                    <IconButton size="sm" aria-label={t('siteSettings.image.remove', { image: title })} onClick={() => onChange(null)}>
                        <TrashIcon size={16} aria-hidden />
                    </IconButton>
                )}
            </div>

            {image ? (
                <Preview field={field} image={image} />
            ) : (
                <button
                    type="button"
                    disabled={!editable}
                    onClick={() => setPicking('upload')}
                    className="flex min-h-29 flex-col items-center justify-center gap-1.5 rounded-xs border border-dashed border-line-strong bg-subtle px-3 py-4 text-center transition-colors duration-150 ease-standard hover:bg-brand-subtle disabled:cursor-not-allowed disabled:hover:bg-subtle"
                >
                    <span aria-hidden className="grid size-8 place-items-center rounded-sm bg-surface text-fg-brand">
                        <CloudArrowUpIcon size={16} />
                    </span>
                    <span className="t-admin-body-strong text-fg-primary">{t('siteSettings.image.upload')}</span>
                    <span className="t-admin-caption text-fg-secondary">{t('siteSettings.image.empty')}</span>
                </button>
            )}

            <div className="flex flex-col gap-0.5">
                {image && (
                    <p className="truncate t-admin-mono-sm text-fg-primary">
                        {image.filename}
                        {image.width && image.height ? ` · ${image.width} × ${image.height}` : ''}
                    </p>
                )}
                <p className="t-admin-caption text-fg-secondary">{t(specKey[field])}</p>
            </div>

            {editable && (
                <div className="mt-auto flex flex-wrap items-center gap-1.5">
                    {image && (
                        <Button variant="outline" size="sm" onClick={() => setPicking('upload')}>
                            <ArrowsClockwiseIcon size={16} aria-hidden />
                            {t('siteSettings.image.replace')}
                        </Button>
                    )}
                    <Button variant={image ? 'ghost' : 'outline'} size="sm" onClick={() => setPicking('library')}>
                        <ImagesIcon size={16} aria-hidden />
                        {image ? t('siteSettings.image.library') : t('siteSettings.image.chooseLibrary')}
                    </Button>
                </div>
            )}

            {error && (
                <p className="t-admin-caption text-fg-primary" role="alert">
                    {error}
                </p>
            )}

            {picking && (
                <MediaPickerModal
                    initialTab={picking}
                    context={{
                        title: t('siteSettings.image.picker', { image: title }),
                        hint: t(specKey[field]),
                        multiple: false,
                        max: 1,
                        kinds: ['image'],
                        defaultFolder: 'pages',
                        ratio: field === 'icon' ? [1, 1] : field === 'social_image' ? [1200, 630] : null,
                        minSize: field === 'icon' ? ICON_MIN : null,
                    }}
                    onInsert={insert}
                    onClose={() => setPicking(null)}
                />
            )}
        </div>
    );
}

/** How the icon reads at app-icon, tab and favicon sizes. */
const iconSizes = [
    { px: 64, cls: 'size-16' },
    { px: 40, cls: 'size-10' },
    { px: 24, cls: 'size-6' },
    { px: 16, cls: 'size-4' },
] as const;

function Preview({ field, image }: { field: ImageField; image: SiteImage }) {
    if (!image.url) return <div className="h-29 rounded-xs bg-subtle" />;
    if (field === 'icon') {
        return (
            <div className="flex h-29 items-end justify-center gap-4 rounded-xs bg-subtle px-3 pb-3">
                {iconSizes.map(({ px, cls }) => (
                    <span key={px} className="flex flex-col items-center gap-1.5">
                        <img src={image.url ?? ''} alt="" width={px} height={px} className={`rounded-xs object-cover ${cls}`} />
                        <span className="t-admin-mono-sm text-fg-tertiary">{px}</span>
                    </span>
                ))}
            </div>
        );
    }
    if (field === 'social_image') return <img src={image.url} alt="" className="aspect-[1200/630] w-full rounded-xs bg-subtle object-cover" />;
    // A dark-background logo is previewed on the admin's dark surface, as it will sit on a dark footer.
    return (
        <div data-theme={field === 'logo_dark' ? 'admin-dark' : undefined} className={`grid h-29 place-items-center rounded-xs p-4 ${field === 'logo_dark' ? 'bg-surface' : 'bg-subtle'}`}>
            <img src={image.url} alt="" className="max-h-full max-w-full object-contain" />
        </div>
    );
}

/** Brand colours from the design tokens — read-only on purpose, so contrast never breaks. */
const swatches = [
    { cls: 'bg-brand', label: 'siteSettings.colours.brand', token: 'bg/brand' },
    { cls: 'bg-accent', label: 'siteSettings.colours.accent', token: 'bg/accent' },
    { cls: 'bg-teal', label: 'siteSettings.colours.teal', token: 'bg/teal' },
    { cls: 'bg-pink', label: 'siteSettings.colours.pink', token: 'bg/pink' },
    { cls: 'bg-canvas', label: 'siteSettings.colours.canvas', token: 'bg/canvas' },
] as const satisfies readonly { cls: string; label: MessageKey; token: string }[];

function BrandColours() {
    const { t } = useI18n();
    return (
        <Card
            icon={PaletteIcon}
            tile="bg-grape-subtle text-grape"
            title={t('siteSettings.colours.title')}
            description={t('siteSettings.colours.description')}
            aside={
                <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-subtle px-2 py-0.5 t-admin-caption-strong text-fg-secondary">
                    <LockSimpleIcon size={12} aria-hidden />
                    {t('siteSettings.colours.readOnly')}
                </span>
            }
        >
            <ul className="grid grid-cols-3 gap-3 sm:grid-cols-5">
                {swatches.map((s) => (
                    <li key={s.token} className="flex min-w-0 flex-col gap-1.5">
                        <span aria-hidden className={`h-11 rounded-xs border border-line-default ${s.cls}`} />
                        <span className="t-admin-caption-strong text-fg-primary">{t(s.label)}</span>
                        <span className="truncate t-admin-mono-sm text-fg-tertiary">{s.token}</span>
                    </li>
                ))}
            </ul>
            <p className="flex items-start gap-2 rounded-xs bg-subtle px-3 py-2.5 t-admin-caption text-fg-secondary">
                <LockSimpleIcon size={16} aria-hidden className="shrink-0 text-fg-brand" />
                <span>{t('siteSettings.colours.note')}</span>
            </p>
        </Card>
    );
}

/** The storefront host shown in the previews (`<meta name="tm-storefront-url">`), else a neutral sample. */
function storefrontHost(): string {
    const raw = typeof document === 'undefined' ? null : document.querySelector<HTMLMetaElement>('meta[name="tm-storefront-url"]')?.content;
    try {
        return raw ? new URL(raw).host : 'toymartbd.com';
    } catch {
        return 'toymartbd.com';
    }
}

/** The title separator the storefront joins a page and the site name with (config/seo.php default). */
const TITLE_SEPARATOR = ' | ';

function LivePreview({ form, site }: { form: Form; site: SiteBranding }) {
    const { t } = useI18n();
    const [locale, setLocale] = useState<'en' | 'bn'>('en');
    const bn = locale === 'bn';
    // As the storefront resolves them: a blank name is the business name in that language; a blank
    // Bangla tagline or description borrows the English one.
    const pick = (en: TextField, bnField: TextField) => (bn ? form.text[bnField].trim() || form.text[en].trim() : form.text[en].trim());
    const name = (bn ? form.text.name_bn.trim() : form.text.name.trim()) || (bn ? site.fallback_names.name_bn : site.fallback_names.name);
    const tagline = pick('tagline', 'tagline_bn');
    const description = pick('description', 'description_bn');
    const title = tagline ? `${name}${TITLE_SEPARATOR}${tagline}` : name;
    const host = storefrontHost();
    const icon = form.images.icon?.url ?? null;
    const logo = form.images.logo?.url ?? null;
    const social = form.images.social_image?.url ?? null;

    const fallbackMark = (size: string, glyph: number) => (
        <span aria-hidden className={`grid shrink-0 place-items-center rounded-xs bg-brand text-fg-on-brand ${size}`}>
            <ShoppingBagOpenIcon size={glyph} weight="fill" />
        </span>
    );
    const logoMark = (
        <span className="flex min-w-0 items-center gap-1.5">
            {logo ? (
                <img src={logo} alt="" className="h-7 max-w-40 object-contain" />
            ) : (
                <>
                    {fallbackMark('size-6', 14)}
                    <span className="truncate t-admin-section text-fg-primary">{name}</span>
                </>
            )}
        </span>
    );

    return (
        <aside aria-label={t('siteSettings.preview.title')} className="flex w-full shrink-0 flex-col gap-3.5 rounded-md border border-line-default bg-surface p-5 shadow-e1 xl:sticky xl:top-6 xl:w-80">
            <div className="flex items-center gap-3">
                <span aria-hidden className="grid size-9 shrink-0 place-items-center rounded-sm bg-teal-subtle text-teal">
                    <BrowserIcon size={18} />
                </span>
                <div className="flex min-w-0 flex-col gap-0.5">
                    <h3 className="t-admin-section text-fg-primary">{t('siteSettings.preview.title')}</h3>
                    <p className="t-admin-caption text-fg-secondary">{t('siteSettings.preview.subtitle')}</p>
                </div>
            </div>
            <SegmentedControl
                label={t('siteSettings.preview.language')}
                value={locale}
                onChange={setLocale}
                className="[&>button]:flex-1"
                options={[
                    { value: 'en', label: t('siteSettings.preview.english'), lang: 'en' },
                    { value: 'bn', label: 'বাংলা', lang: 'bn' },
                ]}
            />
            <div lang={locale} className={`flex flex-col gap-3.5 ${bn ? 'lang-bn' : ''}`}>
                <PreviewLabel>{t('siteSettings.preview.tab')}</PreviewLabel>
                <div className="overflow-hidden rounded-sm border border-line-default bg-subtle">
                    <div className="flex items-end px-2 pt-2">
                        <div className="flex min-w-0 flex-1 items-center gap-2 rounded-t-xs bg-surface px-2.5 py-2">
                            {icon ? <img src={icon} alt="" className="size-4 shrink-0 rounded-xs object-cover" /> : fallbackMark('size-4', 10)}
                            <span className="truncate t-admin-caption-strong text-fg-primary">{title}</span>
                        </div>
                    </div>
                    <div className="flex items-center bg-surface px-2.5 py-2">
                        <span className="flex min-w-0 flex-1 items-center gap-1.5 rounded-full bg-subtle px-2.5 py-1 t-admin-mono-sm text-fg-secondary">
                            <LockSimpleIcon size={12} aria-hidden className="shrink-0 text-fg-tertiary" />
                            <span className="truncate">{host}</span>
                        </span>
                    </div>
                </div>

                <PreviewLabel>{t('siteSettings.preview.header')}</PreviewLabel>
                <div className="flex items-center gap-3 rounded-sm border border-line-default bg-surface px-3.5 py-3">
                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                        {logoMark}
                        {tagline && <span className="truncate t-admin-caption text-fg-secondary">{tagline}</span>}
                    </div>
                    <MagnifyingGlassIcon size={20} aria-hidden className="shrink-0 text-fg-primary" />
                    <ShoppingBagOpenIcon size={20} aria-hidden className="shrink-0 text-fg-primary" />
                </div>

                <PreviewLabel>{t('siteSettings.preview.share')}</PreviewLabel>
                <div className="flex flex-col items-end rounded-sm bg-tile-mint p-3">
                    <div className="flex w-full flex-col gap-2 rounded-sm bg-surface p-1 pb-2">
                        {social ? (
                            <img src={social} alt="" className="aspect-[1200/630] w-full rounded-xs object-cover" />
                        ) : (
                            <div className="flex aspect-[1200/630] w-full flex-col items-center justify-center gap-1 rounded-xs bg-tile-sky px-3 text-center">
                                {logoMark}
                                {tagline && <span className="t-admin-caption text-fg-primary">{tagline}</span>}
                            </div>
                        )}
                        <div className="flex flex-col gap-0.5 px-1.5">
                            <span className="t-admin-caption-strong text-fg-primary">{title}</span>
                            {description && <span className="line-clamp-3 t-admin-caption text-fg-secondary">{description}</span>}
                            <span className="t-admin-mono-sm text-fg-tertiary">{host}</span>
                        </div>
                    </div>
                </div>
                <p className="t-admin-caption text-fg-tertiary">{t('siteSettings.preview.cacheNote')}</p>
            </div>
        </aside>
    );
}

function PreviewLabel({ children }: { children: ReactNode }) {
    return <p className="t-admin-overline text-fg-tertiary">{children}</p>;
}
