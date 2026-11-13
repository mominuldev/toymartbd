import { useState, type ReactNode } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { InfoIcon } from '@phosphor-icons/react';
import { bannerRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Field, inputClass } from '@/components/ui/Field';
import { FormSection } from '@/components/ui/FormSection';
import { FormActions } from '@/components/ui/FormActions';
import { Select } from '@/components/ui/Select';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { QueryError } from '@/components/ui/QueryError';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { flatten as flattenCategories, useCategoryTree } from '@/features/catalog/categories/api';
import { MediaField } from '../shared/MediaField';
import { apiFieldErrors, issuesToErrors, type FieldErrors } from '../shared/errors';
import { placements, useBanner, useDeleteBanner, useSaveBanner, type Banner } from './api';
import { allowsImage, bannerRuleErrors, toBannerForm, toBannerPayload, type BannerForm } from './form';

const ruleMessage: Record<string, MessageKey> = {
    content: 'content.banners.error.content',
    ends: 'content.banners.error.ends',
    infoLink: 'content.banners.error.infoLink',
    link: 'content.banners.error.link',
};

/** Create and edit a banner. No Figma frame: built in the Product edit (B · 12) form language. */
export function BannerFormPage() {
    const params = useParams();
    const id = params.bannerId ? Number(params.bannerId) : null;
    const banner = useBanner(id);

    if (id !== null && banner.isError) return <QueryError error={banner.error} onRetry={() => void banner.refetch()} />;
    if (id !== null && !banner.data) return <p aria-busy="true" className="sr-only" />;

    return <BannerEditor key={banner.data?.id ?? 'new'} id={id} banner={banner.data} />;
}

function BannerEditor({ id, banner }: { id: number | null; banner?: Banner }) {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const navigate = useNavigate();
    const save = useSaveBanner(id);
    const remove = useDeleteBanner(id ?? 0);
    const categories = useCategoryTree();
    const [form, setForm] = useState<BannerForm>(() => toBannerForm(banner));
    const [errors, setErrors] = useState<FieldErrors>({});
    const [confirming, setConfirming] = useState(false);
    const editable = can(me, 'content.manage');
    const set = <K extends keyof BannerForm>(key: K, value: BannerForm[K]) => setForm((f) => ({ ...f, [key]: value }));
    const err = (field: string) => errors[field];

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const payload = toBannerPayload(form);
        const parsed = bannerRequestSchema.safeParse(payload);
        const local: FieldErrors = parsed.success ? {} : issuesToErrors(parsed.error.issues);
        for (const [field, rule] of Object.entries(bannerRuleErrors(form))) local[field] ??= t(ruleMessage[rule]!);
        setErrors(local);
        if (Object.keys(local).length > 0) return;

        save.mutate(payload, {
            onSuccess: (saved) => {
                notify(t(id === null ? 'content.banners.created' : 'content.banners.saved'));
                if (id === null) navigate(`/store/banners/${saved.id}`, { replace: true });
            },
            onError: (error) => {
                const fields = apiFieldErrors(error);
                if (fields) setErrors(fields);
                else notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger');
            },
        });
    };

    const text = (key: keyof BannerForm, label: MessageKey, opts: { lang?: string; hint?: string; optional?: boolean; area?: boolean; type?: string } = {}): ReactNode => (
        <Field label={t(label)} hint={opts.hint} error={err(key)} optional={opts.optional}>
            {({ id: fid, describedBy, invalid }) =>
                opts.area ? (
                    <textarea id={fid} rows={2} lang={opts.lang} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`py-2 ${inputClass}`} value={form[key] as string} onChange={(e) => set(key, e.target.value as never)} />
                ) : (
                    <input
                        id={fid}
                        type={opts.type ?? 'text'}
                        lang={opts.lang}
                        aria-describedby={describedBy}
                        aria-invalid={invalid || undefined}
                        className={`h-10 ${opts.type ? 'tabular ' : ''}${inputClass}`}
                        value={form[key] as string}
                        onChange={(e) => set(key, e.target.value as never)}
                    />
                )
            }
        </Field>
    );

    const title = id === null ? t('content.banners.new') : t('content.banners.edit');
    const isInfo = form.placement === 'info_strip';

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('content.crumb.store') }, { label: t('content.banners.title'), to: '/store/banners' }, { label: title }]}
                title={banner?.name || title}
                titleAddon={banner && <StatusChip tone={banner.state === 'live' ? 'success' : banner.state === 'scheduled' ? 'info' : 'neutral'} label={t(`content.banners.state.${banner.state}`)} />}
            />

            <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                <fieldset disabled={!editable || save.isPending} className="grid min-w-0 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
                    <div className="flex min-w-0 flex-col gap-6">
                        <FormSection>
                            <Field label={t('content.banners.field.placement')} error={err('placement')}>
                                {({ id: fid }) => (
                                    <Select id={fid} value={form.placement} onChange={(e) => set('placement', e.target.value as BannerForm['placement'])}>
                                        {placements.map((p) => (
                                            <option key={p} value={p}>
                                                {t(`content.banners.placement.${p}`)}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                            {isInfo && (
                                <p className="flex items-start gap-2 rounded-sm border border-line-default bg-subtle p-3 t-admin-caption text-fg-primary">
                                    <InfoIcon size={16} aria-hidden className="mt-px shrink-0 text-feedback-info" />
                                    {t('content.banners.infoStripNote')}
                                </p>
                            )}
                            {text('name', 'content.banners.field.name', { hint: t('content.banners.field.nameHint') })}
                            <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                                {text('title', 'content.banners.field.title', { optional: true })}
                                {text('title_bn', 'content.banners.field.titleBn', { lang: 'bn', optional: true })}
                                {text('body', 'content.banners.field.body', { optional: true, area: true })}
                                {text('body_bn', 'content.banners.field.bodyBn', { lang: 'bn', optional: true, area: true })}
                            </div>
                            {allowsImage(form.placement) && (
                                <MediaField
                                    label={t('content.banners.field.image')}
                                    hint={t('content.banners.field.imageHint')}
                                    pickerTitle="content.banners.field.pickerTitle"
                                    value={form.image}
                                    onChange={(image) => set('image', image)}
                                    disabled={!editable}
                                    ratio={[3, 1]}
                                    error={err('media_id')}
                                />
                            )}
                            {text('link_url', 'content.banners.field.link', { optional: true, hint: t(isInfo ? 'content.banners.field.linkHintInfo' : 'content.banners.field.linkHint') })}
                            <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                                {text('link_label', 'content.banners.field.linkLabel', { optional: true })}
                                {text('link_label_bn', 'content.banners.field.linkLabelBn', { lang: 'bn', optional: true })}
                            </div>
                            {form.placement === 'category_page' && (
                                <Field label={t('content.banners.field.category')} error={err('category_id')} optional>
                                    {({ id: fid }) => (
                                        <Select id={fid} value={form.category_id} onChange={(e) => set('category_id', e.target.value)}>
                                            <option value="">{t('content.banners.allCategories')}</option>
                                            {flattenCategories(categories.data ?? []).map(({ node, depth }) => (
                                                <option key={node.id} value={String(node.id)}>
                                                    {`${'— '.repeat(depth)}${locale === 'bn' && node.name_bn ? node.name_bn : node.name}`}
                                                </option>
                                            ))}
                                        </Select>
                                    )}
                                </Field>
                            )}
                        </FormSection>
                    </div>

                    <aside className="flex min-w-0 flex-col gap-6">
                        <FormSection title={t('content.banners.col.schedule')}>
                            {text('starts_at', 'content.banners.field.startsAt', { type: 'datetime-local', optional: true, hint: t('content.banners.field.scheduleHint') })}
                            {text('ends_at', 'content.banners.field.endsAt', { type: 'datetime-local', optional: true })}
                            {text('priority', 'content.banners.field.priority', { type: 'number', hint: t('content.banners.field.priorityHint') })}
                            <label className="flex min-h-touch-min items-center gap-3 t-admin-body text-fg-primary">
                                <input type="checkbox" className="size-4 accent-brand" checked={form.is_active} onChange={(e) => set('is_active', e.target.checked)} />
                                {t('content.banners.field.active')}
                            </label>
                        </FormSection>
                    </aside>
                </fieldset>

                <FormActions
                    start={
                        banner && editable ? (
                            <Button variant="danger" onClick={() => setConfirming(true)}>
                                {t('content.banners.delete')}
                            </Button>
                        ) : undefined
                    }
                >
                    <Link to="/store/banners" className={buttonClass('ghost')}>
                        {t('common.cancel')}
                    </Link>
                    {editable && (
                        <Button type="submit" disabled={save.isPending}>
                            {save.isPending ? t('common.saving') : t('common.save')}
                        </Button>
                    )}
                </FormActions>
            </form>

            {banner && (
                <ConfirmDialog
                    open={confirming}
                    title={t('content.banners.deleteTitle', { name: banner.name })}
                    body={t('content.banners.deleteBody')}
                    confirmLabel={t('content.banners.delete')}
                    pending={remove.isPending}
                    onClose={() => setConfirming(false)}
                    onConfirm={() =>
                        remove.mutate(undefined, {
                            onSuccess: () => {
                                setConfirming(false);
                                notify(t('content.banners.deleted'));
                                navigate('/store/banners', { replace: true });
                            },
                        })
                    }
                />
            )}
        </section>
    );
}
