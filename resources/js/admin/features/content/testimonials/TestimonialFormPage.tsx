import { useState, type ReactNode } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { testimonialRequestSchema, type TestimonialRequestInput } from '@toymart/api-types/zod';
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
import { useToast } from '@/components/ui/Toast';
import { MediaField, type PickedImage } from '../shared/MediaField';
import { apiFieldErrors, issuesToErrors, type FieldErrors } from '../shared/errors';
import { useDeleteTestimonial, useSaveTestimonial, useTestimonial, type Testimonial } from './api';

export type TestimonialForm = {
    name: string;
    name_bn: string;
    location: string;
    location_bn: string;
    quote: string;
    quote_bn: string;
    rating: string;
    photo: PickedImage | null;
    is_published: boolean;
};

export function toTestimonialForm(x?: Testimonial): TestimonialForm {
    return {
        name: x?.name ?? '',
        name_bn: x?.name_bn ?? '',
        location: x?.location ?? '',
        location_bn: x?.location_bn ?? '',
        quote: x?.quote ?? '',
        quote_bn: x?.quote_bn ?? '',
        rating: x?.rating ? String(x.rating) : '',
        photo: x?.media_id ? { media_id: x.media_id, url: x.photo?.url ?? null, alt: x.photo?.alt ?? null } : null,
        is_published: x?.is_published ?? false,
    };
}

const orNull = (value: string): string | null => (value.trim() === '' ? null : value.trim());

export function toTestimonialPayload(form: TestimonialForm): TestimonialRequestInput {
    return {
        name: form.name.trim(),
        name_bn: orNull(form.name_bn),
        location: orNull(form.location),
        location_bn: orNull(form.location_bn),
        quote: form.quote.trim(),
        quote_bn: orNull(form.quote_bn),
        rating: form.rating === '' ? null : Number(form.rating),
        media_id: form.photo?.media_id ?? null,
        is_published: form.is_published,
    };
}

/** Create and edit a testimonial. No Figma frame: Product edit (B · 12) form language. */
export function TestimonialFormPage() {
    const params = useParams();
    const id = params.testimonialId ? Number(params.testimonialId) : null;
    const query = useTestimonial(id);

    if (id !== null && query.isError) return <QueryError error={query.error} onRetry={() => void query.refetch()} />;
    if (id !== null && !query.data) return <p aria-busy="true" className="sr-only" />;

    return <TestimonialEditor key={query.data?.id ?? 'new'} id={id} testimonial={query.data} />;
}

function TestimonialEditor({ id, testimonial }: { id: number | null; testimonial?: Testimonial }) {
    const { t } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const navigate = useNavigate();
    const save = useSaveTestimonial(id);
    const remove = useDeleteTestimonial(id ?? 0);
    const [form, setForm] = useState<TestimonialForm>(() => toTestimonialForm(testimonial));
    const [errors, setErrors] = useState<FieldErrors>({});
    const [confirming, setConfirming] = useState(false);
    const editable = can(me, 'content.manage');
    const set = <K extends keyof TestimonialForm>(key: K, value: TestimonialForm[K]) => setForm((f) => ({ ...f, [key]: value }));

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const payload = toTestimonialPayload(form);
        const parsed = testimonialRequestSchema.safeParse(payload);
        const local = parsed.success ? {} : issuesToErrors(parsed.error.issues);
        setErrors(local);
        if (Object.keys(local).length > 0) return;
        save.mutate(payload, {
            onSuccess: (saved) => {
                notify(t(id === null ? 'content.testimonials.created' : 'content.testimonials.saved'));
                if (id === null) navigate(`/store/testimonials/${saved.id}`, { replace: true });
            },
            onError: (error) => {
                const fields = apiFieldErrors(error);
                if (fields) setErrors(fields);
                else notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger');
            },
        });
    };

    const text = (key: Exclude<keyof TestimonialForm, 'photo' | 'is_published'>, label: MessageKey, opts: { lang?: string; optional?: boolean; area?: boolean } = {}): ReactNode => (
        <Field label={t(label)} error={errors[key]} optional={opts.optional}>
            {({ id: fid, describedBy, invalid }) =>
                opts.area ? (
                    <textarea id={fid} rows={3} lang={opts.lang} maxLength={600} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`py-2 ${inputClass}`} value={form[key]} onChange={(e) => set(key, e.target.value)} />
                ) : (
                    <input id={fid} lang={opts.lang} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${inputClass}`} value={form[key]} onChange={(e) => set(key, e.target.value)} />
                )
            }
        </Field>
    );

    const title = id === null ? t('content.testimonials.new') : t('content.testimonials.edit');

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader breadcrumb={[{ label: t('content.crumb.store') }, { label: t('content.testimonials.title'), to: '/store/testimonials' }, { label: title }]} title={title} />

            <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                <fieldset disabled={!editable || save.isPending} className="flex max-w-3xl min-w-0 flex-col gap-6">
                    <FormSection>
                        <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                            {text('name', 'content.testimonials.field.name')}
                            {text('name_bn', 'content.testimonials.field.nameBn', { lang: 'bn', optional: true })}
                            {text('location', 'content.testimonials.field.location', { optional: true })}
                            {text('location_bn', 'content.testimonials.field.locationBn', { lang: 'bn', optional: true })}
                            {text('quote', 'content.testimonials.field.quote', { area: true })}
                            {text('quote_bn', 'content.testimonials.field.quoteBn', { lang: 'bn', optional: true, area: true })}
                        </div>
                        <Field label={t('content.testimonials.field.rating')} error={errors.rating} optional>
                            {({ id: fid }) => (
                                <Select id={fid} value={form.rating} onChange={(e) => set('rating', e.target.value)} className="md:w-56">
                                    <option value="">{t('content.testimonials.field.noRating')}</option>
                                    {[5, 4, 3, 2, 1].map((n) => (
                                        <option key={n} value={String(n)}>
                                            {t('content.testimonials.field.stars', { n })}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                        <MediaField
                            label={t('content.testimonials.field.photo')}
                            hint={t('content.testimonials.field.photoHint')}
                            pickerTitle="content.testimonials.field.pickerTitle"
                            value={form.photo}
                            onChange={(photo) => set('photo', photo)}
                            disabled={!editable}
                            ratio={[1, 1]}
                            error={errors.media_id}
                        />
                        <label className="flex min-h-touch-min items-center gap-3 t-admin-body text-fg-primary">
                            <input type="checkbox" className="size-4 accent-brand" checked={form.is_published} onChange={(e) => set('is_published', e.target.checked)} />
                            {t('content.testimonials.field.published')}
                        </label>
                    </FormSection>
                </fieldset>

                <FormActions
                    start={
                        testimonial && editable ? (
                            <Button variant="danger" onClick={() => setConfirming(true)}>
                                {t('content.testimonials.delete')}
                            </Button>
                        ) : undefined
                    }
                >
                    <Link to="/store/testimonials" className={buttonClass('ghost')}>
                        {t('common.cancel')}
                    </Link>
                    {editable && (
                        <Button type="submit" disabled={save.isPending}>
                            {save.isPending ? t('common.saving') : t('common.save')}
                        </Button>
                    )}
                </FormActions>
            </form>

            {testimonial && (
                <ConfirmDialog
                    open={confirming}
                    title={t('content.testimonials.deleteTitle', { name: testimonial.name })}
                    body={t('content.testimonials.deleteBody')}
                    confirmLabel={t('content.testimonials.delete')}
                    pending={remove.isPending}
                    onClose={() => setConfirming(false)}
                    onConfirm={() =>
                        remove.mutate(undefined, {
                            onSuccess: () => {
                                setConfirming(false);
                                notify(t('content.testimonials.deleted'));
                                navigate('/store/testimonials', { replace: true });
                            },
                        })
                    }
                />
            )}
        </section>
    );
}
