import { useEffect, useId, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { Link, useNavigate } from 'react-router';
import {
    CheckIcon,
    ClipboardTextIcon,
    CopyIcon,
    MagnifyingGlassIcon,
    MapPinIcon,
    MinusIcon,
    PackageIcon,
    PhoneIcon,
    PlusIcon,
    ShieldCheckIcon,
    TrashIcon,
    WarningIcon,
} from '@phosphor-icons/react';
import { storeOrderRequestSchema, updateOrderRequestSchema } from '@toymart/api-types/zod';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button } from '@/components/ui/Button';
import { Field, inputClass } from '@/components/ui/Field';
import { FormSection } from '@/components/ui/FormSection';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { ApiError } from '@/lib/api';
import type { Order } from './api';
import {
    PHONE_PATTERN,
    staffSources,
    useCreateOrder,
    useCreateQuote,
    useCustomerLookup,
    useDebounced,
    useDistricts,
    useEditQuote,
    useUpazilas,
    useUpdateOrder,
    useVariantSearch,
    type ComposerVariant,
    type EditQuoteBody,
    type Line,
    type Quote,
    type StaffSource,
} from './composeApi';
import { Taka, initials, takaText } from './format';
import { statusLabel } from './parts';

/** What the operator sees for a line before the quote answers: the picker's row (display only). */
type LineInfo = { name: string; sku: string; image: string | null; price: string; available: number | null };

type Mode = { kind: 'create' } | { kind: 'edit'; order: Order };

type Form = {
    phone: string;
    source: StaffSource;
    name: string;
    altPhone: string;
    divisionId: number | null;
    districtId: number | null;
    /** `null` = not chosen; `0` = the district's city (no upazila). */
    upazilaId: number | null;
    area: string;
    addressLine: string;
    landmark: string;
    coupon: string;
    customerNote: string;
    internalNote: string;
    note: string;
};

const emptyForm: Form = {
    phone: '',
    source: 'phone',
    name: '',
    altPhone: '',
    divisionId: null,
    districtId: null,
    upazilaId: null,
    area: '',
    addressLine: '',
    landmark: '',
    coupon: '',
    customerNote: '',
    internalNote: '',
    note: '',
};

function formFromOrder(order: Order): Form {
    const s = order.shipping;
    return {
        ...emptyForm,
        phone: s.phone.replace(/^\+88/, ''),
        name: s.name,
        altPhone: s.alt_phone?.replace(/^\+88/, '') ?? '',
        divisionId: s.division_id,
        districtId: s.district_id,
        upazilaId: s.district_id === null ? null : (s.upazila_id ?? 0),
        area: s.area ?? '',
        addressLine: s.address ?? '',
        landmark: s.landmark ?? '',
    };
}

const textareaClass = `${inputClass} min-h-10 py-2`;

/**
 * The create-order screen (Figma "Create order" 207:2243, Theme B colours) and, with
 * `mode.kind = 'edit'`, the edit-after-placement screen built from the same sections.
 *
 * Left: 1 · Customer (phone lookup first) · 2 · Delivery address (division → district →
 * upazila → area + line + landmark, rule 31) · 3 · Products · 4 · Notes. Right rail:
 * Summary (the server's quote, re-asked on every change), Payment (COD; online methods
 * wait for the gateway decision) and the submit button that carries the total.
 */
export function OrderComposer({ mode }: { mode: Mode }) {
    const { t, locale } = useI18n();
    const toast = useToast();
    const navigate = useNavigate();
    const order = mode.kind === 'edit' ? mode.order : null;

    const [form, setForm] = useState<Form>(() => (order ? formFromOrder(order) : emptyForm));
    const [lines, setLines] = useState<Line[]>(() =>
        order ? order.items.filter((i) => i.quantity > 0 && i.variant_id !== null).map((i) => ({ variant_id: i.variant_id as number, quantity: i.quantity })) : [],
    );
    const [info, setInfo] = useState<Record<number, LineInfo>>(() =>
        Object.fromEntries(
            (order?.items ?? [])
                .filter((i) => i.variant_id !== null)
                .map((i) => [i.variant_id as number, { name: i.product_name, sku: i.sku, image: null, price: i.unit_price, available: null }]),
        ),
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const set = <K extends keyof Form>(key: K, value: Form[K]) => {
        setForm((f) => ({ ...f, [key]: value }));
        setErrors((e) => {
            const { [key]: _drop, ...rest } = e;
            return rest;
        });
    };

    const phoneDigits = form.phone.replace(/[\s-]/g, '');
    const phoneValid = PHONE_PATTERN.test(phoneDigits);
    const lookup = useCustomerLookup(mode.kind === 'create' ? phoneDigits : '');
    const districts = useDistricts();
    const upazilas = useUpazilas(form.districtId);
    const district = districts.data?.find((d) => d.id === form.districtId) ?? null;

    // A returning customer pre-fills the name and their default address, once, into empty fields.
    const prefilled = useRef<string | null>(null);
    useEffect(() => {
        const found = lookup.data;
        if (!found || prefilled.current === found.phone) return;
        prefilled.current = found.phone;
        const address = found.addresses[0];
        setForm((f) => ({
            ...f,
            name: f.name || found.customer?.name || '',
            ...(address && f.districtId === null
                ? {
                      divisionId: address.division_id,
                      districtId: address.district_id,
                      upazilaId: address.upazila_id ?? 0,
                      area: address.area ?? '',
                      addressLine: address.address_line ?? '',
                      landmark: address.landmark ?? '',
                  }
                : {}),
        }));
    }, [lookup.data]);

    const destination = {
        district_id: form.districtId,
        upazila_id: form.upazilaId === null || form.upazilaId === 0 ? null : form.upazilaId,
    };
    const createBody = useDebounced({
        lines,
        coupon_code: form.coupon.trim() || null,
        phone: phoneValid ? phoneDigits : null,
        ...(form.districtId !== null && form.upazilaId !== null ? destination : {}),
    });
    const editBody: EditQuoteBody | null =
        order && form.districtId !== null && form.upazilaId !== null
            ? {
                  lines,
                  name: form.name.trim(),
                  phone: phoneDigits,
                  alt_phone: form.altPhone.trim() || null,
                  division_id: form.divisionId,
                  ...destination,
                  district_id: form.districtId,
                  area: form.area.trim(),
                  address_line: form.addressLine.trim(),
                  landmark: form.landmark.trim(),
              }
            : null;
    const debouncedEdit = useDebounced(editBody);
    const createQuote = useCreateQuote(mode.kind === 'create' ? createBody : { lines: [] });
    const editQuote = useEditQuote(order?.id ?? 0, mode.kind === 'edit' ? debouncedEdit : null);
    const quoteQuery = mode.kind === 'create' ? createQuote : editQuote;
    const quote = lines.length > 0 ? quoteQuery.data : undefined;

    const create = useCreateOrder();
    const update = useUpdateOrder(order?.id ?? 0);
    const pending = create.isPending || update.isPending;

    const divisions = useMemo(() => {
        const seen = new Map<number, { id: number; name: string; name_bn: string }>();
        for (const d of districts.data ?? []) seen.set(d.division.id, d.division);
        return [...seen.values()];
    }, [districts.data]);

    const addVariant = (v: ComposerVariant) => {
        setInfo((current) => ({ ...current, [v.variant_id]: { name: v.product_name, sku: v.sku, image: v.image_url, price: v.price, available: v.available } }));
        setLines((current) =>
            current.some((l) => l.variant_id === v.variant_id)
                ? current.map((l) => (l.variant_id === v.variant_id ? { ...l, quantity: Math.min(99, l.quantity + 1) } : l))
                : [...current, { variant_id: v.variant_id, quantity: 1 }],
        );
        setErrors((e) => {
            const { lines: _drop, ...rest } = e;
            return rest;
        });
    };
    const setQuantity = (variantId: number, quantity: number) =>
        setLines((current) => current.map((l) => (l.variant_id === variantId ? { ...l, quantity: Math.max(1, Math.min(99, quantity)) } : l)));
    const removeLine = (variantId: number) => setLines((current) => current.filter((l) => l.variant_id !== variantId));

    const fail = (error: unknown) => {
        if (error instanceof ApiError) {
            const fieldErrors: Record<string, string> = {};
            for (const e of error.problem.errors ?? []) fieldErrors[e.field.startsWith('lines.') ? 'lines' : e.field] ??= e.message;
            setErrors(fieldErrors);
            if (error.is('price-changed')) {
                void quoteQuery.refetch();
                toast.notify(error.fieldError('expected_total') ?? error.problem.title, 'danger');
            } else toast.notify(error.problem.errors?.length ? t('orders.compose.fixErrors') : (error.problem.detail ?? error.problem.title), 'danger');
            return;
        }
        toast.notify(t('error.title'), 'danger');
    };

    const zodErrors = (issues: { path: PropertyKey[]; message: string }[]) => {
        const map: Record<string, string> = {};
        for (const issue of issues) map[String(issue.path[0] ?? 'form')] ??= issue.message;
        setErrors(map);
        toast.notify(t('orders.compose.fixErrors'), 'danger');
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!quote) return;
        const common = {
            lines,
            name: form.name.trim(),
            phone: phoneDigits,
            alt_phone: form.altPhone.trim() || null,
            division_id: form.divisionId,
            district_id: form.districtId ?? 0,
            upazila_id: destination.upazila_id,
            area: form.area.trim(),
            address_line: form.addressLine.trim(),
            landmark: form.landmark.trim(),
            expected_total: quote.grand_total,
        };
        if (mode.kind === 'create') {
            const parsed = storeOrderRequestSchema.safeParse({
                ...common,
                source: form.source,
                coupon_code: form.coupon.trim() || null,
                customer_note: form.customerNote.trim() || null,
                internal_note: form.internalNote.trim() || null,
            });
            if (!parsed.success) return zodErrors(parsed.error.issues);
            create.mutate(parsed.data, {
                onSuccess: (created) => {
                    toast.notify(t('orders.compose.created', { number: created.number }));
                    void navigate(`/orders/${created.id}`);
                },
                onError: fail,
            });
            return;
        }
        const parsed = updateOrderRequestSchema.safeParse({ ...common, note: form.note.trim() || null });
        if (!parsed.success) return zodErrors(parsed.error.issues);
        update.mutate(parsed.data, {
            onSuccess: (saved) => {
                toast.notify(t('orders.edit.saved', { number: saved.number }));
                void navigate(`/orders/${saved.id}`);
            },
            onError: fail,
        });
    };

    const blockers = quote?.blockers ?? [];
    const blocked = (field: string) => blockers.find((b) => b.field === field)?.message;
    const quoteError = quoteQuery.error instanceof ApiError ? quoteQuery.error : null;
    const ready = Boolean(quote?.ready) && !quoteQuery.isFetching && lines.length > 0;
    const open = lookup.data?.open_orders[0];

    return (
        <form noValidate onSubmit={submit} className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[
                    { label: t('nav.group.sell') },
                    { label: t('orders.crumb'), to: '/orders' },
                    order ? { label: order.number, to: `/orders/${order.id}` } : { label: t('orders.compose.crumb') },
                ]}
                title={order ? t('orders.edit.title', { number: order.number }) : t('orders.compose.title')}
                subtitle={order ? t('orders.edit.subtitle') : t('orders.compose.subtitle')}
                actions={
                    order ? (
                        <Link to={`/orders/${order.id}`} className="inline-flex h-9 items-center rounded-sm px-3.5 t-admin-button text-fg-primary hover:bg-subtle">
                            {t('orders.edit.cancel')}
                        </Link>
                    ) : (
                        <>
                            <Soon label={t('orders.compose.soon', { feature: t('orders.compose.saveDraft') })}>
                                {(id) => (
                                    <SoonButton variant="ghost" describedBy={id}>
                                        {t('orders.compose.saveDraft')}
                                    </SoonButton>
                                )}
                            </Soon>
                            <Soon label={t('orders.compose.soon', { feature: t('orders.compose.pasteChat') })}>
                                {(id) => (
                                    <SoonButton variant="outline" describedBy={id}>
                                        <CopyIcon size={16} aria-hidden />
                                        {t('orders.compose.pasteChat')}
                                    </SoonButton>
                                )}
                            </Soon>
                        </>
                    )
                }
            />

            <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_21.75rem]">
                <div className="flex min-w-0 flex-col gap-6">
                    {/* 1 · Customer */}
                    <FormSection title={t('orders.compose.customer')} description={t('orders.compose.customerHint')}>
                        <div className="grid grid-cols-1 gap-3.5 md:grid-cols-2">
                            <Field label={t('orders.compose.phone')} required hint={phoneValid ? t('orders.compose.phoneHint') : undefined} error={errors.phone}>
                                {({ id, describedBy, invalid }) => (
                                    <Input
                                        id={id}
                                        aria-describedby={describedBy}
                                        aria-invalid={invalid || undefined}
                                        inputMode="tel"
                                        autoComplete="off"
                                        leading={<PhoneIcon size={16} aria-hidden />}
                                        value={form.phone}
                                        onChange={(e) => set('phone', e.target.value)}
                                        className="tabular"
                                    />
                                )}
                            </Field>
                            {mode.kind === 'create' ? (
                                <Field label={t('orders.compose.source')} error={errors.source}>
                                    {({ id, describedBy, invalid }) => (
                                        <Select id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={form.source} onChange={(e) => set('source', e.target.value as StaffSource)}>
                                            {staffSources.map((s) => (
                                                <option key={s} value={s}>
                                                    {t(`orders.compose.source.${s}` as MessageKey)}
                                                </option>
                                            ))}
                                        </Select>
                                    )}
                                </Field>
                            ) : (
                                <Field label={t('orders.compose.altPhone')} optional error={errors.alt_phone}>
                                    {({ id, describedBy, invalid }) => (
                                        <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} inputMode="tel" value={form.altPhone} onChange={(e) => set('altPhone', e.target.value)} className="tabular" />
                                    )}
                                </Field>
                            )}
                            <Field label={t('orders.compose.name')} required error={errors.name}>
                                {({ id, describedBy, invalid }) => (
                                    <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} autoComplete="off" value={form.name} onChange={(e) => set('name', e.target.value)} />
                                )}
                            </Field>
                        </div>

                        {mode.kind === 'create' && phoneValid && (
                            <CustomerCard loading={lookup.isFetching && !lookup.data} customer={lookup.data?.customer ?? null} phone={phoneDigits} />
                        )}
                        {mode.kind === 'create' && open && (
                            <div className="flex flex-wrap items-center gap-3 rounded-md border-l-3 border-feedback-warning bg-subtle px-4 py-3">
                                <WarningIcon size={18} aria-hidden className="shrink-0 text-status-low-stock" />
                                <div className="flex min-w-0 flex-1 basis-64 flex-col gap-0.5">
                                    <p className="t-admin-body-strong text-fg-primary">{t('orders.compose.openOrder')}</p>
                                    <p className="t-admin-caption text-fg-secondary">
                                        {t('orders.compose.openOrderBody', { number: open.number, status: t(statusLabel(open.status as Order['status'])), total: takaText(open.grand_total) })}
                                    </p>
                                </div>
                                <Link to={`/orders/${open.id}/edit`} className="inline-flex h-8 shrink-0 items-center rounded-sm border border-line-default bg-surface px-3 t-admin-button-sm text-fg-primary hover:bg-subtle">
                                    {t('orders.compose.openOrderEdit', { number: open.number })}
                                </Link>
                            </div>
                        )}
                    </FormSection>

                    {/* 2 · Delivery address */}
                    <FormSection title={t('orders.compose.address')} description={t('orders.compose.addressHint')}>
                        <div className="grid grid-cols-1 gap-3.5 md:grid-cols-2">
                            <Field label={t('orders.compose.division')} error={errors.division_id}>
                                {({ id, describedBy, invalid }) => (
                                    <Select
                                        id={id}
                                        aria-describedby={describedBy}
                                        aria-invalid={invalid || undefined}
                                        value={form.divisionId ?? ''}
                                        onChange={(e) => {
                                            set('divisionId', e.target.value ? Number(e.target.value) : null);
                                            set('districtId', null);
                                            set('upazilaId', null);
                                        }}
                                    >
                                        <option value="">{t('orders.compose.choose')}</option>
                                        {divisions.map((d) => (
                                            <option key={d.id} value={d.id}>
                                                {label(d)}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                            <Field label={t('orders.compose.district')} required error={errors.district_id ?? blocked('district_id')}>
                                {({ id, describedBy, invalid }) => (
                                    <Select
                                        id={id}
                                        aria-describedby={describedBy}
                                        aria-invalid={invalid || undefined}
                                        value={form.districtId ?? ''}
                                        onChange={(e) => {
                                            const next = districts.data?.find((d) => d.id === Number(e.target.value)) ?? null;
                                            set('districtId', next?.id ?? null);
                                            if (next) set('divisionId', next.division.id);
                                            set('upazilaId', null);
                                        }}
                                    >
                                        <option value="">{t('orders.compose.choose')}</option>
                                        {(districts.data ?? [])
                                            .filter((d) => form.divisionId === null || d.division.id === form.divisionId)
                                            .map((d) => (
                                                <option key={d.id} value={d.id}>
                                                    {label(d)}
                                                </option>
                                            ))}
                                    </Select>
                                )}
                            </Field>
                            <Field label={t('orders.compose.upazila')} required={!district?.city_option} error={errors.upazila_id ?? blocked('upazila_id')}>
                                {({ id, describedBy, invalid }) => (
                                    <Select
                                        id={id}
                                        aria-describedby={describedBy}
                                        aria-invalid={invalid || undefined}
                                        disabled={form.districtId === null}
                                        value={form.upazilaId === null ? '' : String(form.upazilaId)}
                                        onChange={(e) => set('upazilaId', e.target.value === '' ? null : Number(e.target.value))}
                                    >
                                        <option value="">{t('orders.compose.choose')}</option>
                                        {district?.city_option && <option value="0">{t('orders.compose.upazilaCity')}</option>}
                                        {(upazilas.data ?? []).map((u) => (
                                            <option key={u.id} value={u.id}>
                                                {u.serviceable ? label(u) : t('orders.compose.notServiced', { name: label(u) })}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                            <Field label={t('orders.compose.area')} required error={errors.area}>
                                {({ id, describedBy, invalid }) => (
                                    <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} placeholder={t('orders.compose.areaPlaceholder')} value={form.area} onChange={(e) => set('area', e.target.value)} />
                                )}
                            </Field>
                        </div>
                        <Field label={t('orders.compose.addressLine')} required hint={t('orders.compose.addressLineHint')} error={errors.address_line}>
                            {({ id, describedBy, invalid }) => (
                                <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={form.addressLine} onChange={(e) => set('addressLine', e.target.value)} />
                            )}
                        </Field>
                        <Field label={t('orders.compose.landmark')} required hint={t('orders.compose.landmarkHint')} error={errors.landmark}>
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    aria-describedby={describedBy}
                                    aria-invalid={invalid || undefined}
                                    leading={<MapPinIcon size={16} aria-hidden />}
                                    value={form.landmark}
                                    onChange={(e) => set('landmark', e.target.value)}
                                />
                            )}
                        </Field>
                        {quote?.delivery?.zone && (
                            <p className="flex items-center gap-2 rounded-sm bg-subtle px-3 py-2 t-admin-caption text-fg-secondary">
                                <CheckIcon size={14} aria-hidden className="shrink-0 text-feedback-success" />
                                {t('orders.compose.zoneNote', { zone: t(`orders.compose.zone.${quote.delivery.zone}` as MessageKey) })}
                            </p>
                        )}
                    </FormSection>

                    {/* 3 · Products */}
                    <ProductsSection
                        lines={lines}
                        info={info}
                        quote={quote}
                        error={errors.lines ?? blockers.find((b) => b.field.startsWith('lines'))?.message}
                        onAdd={addVariant}
                        onQuantity={setQuantity}
                        onRemove={removeLine}
                    />

                    {/* 4 · Notes */}
                    <FormSection title={t('orders.compose.notes')}>
                        {mode.kind === 'create' ? (
                            <>
                                <Field label={t('orders.compose.packerNote')} hint={t('orders.compose.packerNoteHint')} error={errors.customer_note}>
                                    {({ id, describedBy, invalid }) => (
                                        <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={form.customerNote} onChange={(e) => set('customerNote', e.target.value)} />
                                    )}
                                </Field>
                                <Field label={t('orders.compose.internalNote')} hint={t('orders.compose.internalNoteHint')} error={errors.internal_note}>
                                    {({ id, describedBy, invalid }) => (
                                        <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={form.internalNote} onChange={(e) => set('internalNote', e.target.value)} />
                                    )}
                                </Field>
                            </>
                        ) : (
                            <Field label={t('orders.edit.note')} optional hint={t('orders.edit.noteHint')} error={errors.note}>
                                {({ id, describedBy, invalid }) => (
                                    <textarea id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} rows={2} className={textareaClass} value={form.note} onChange={(e) => set('note', e.target.value)} />
                                )}
                            </Field>
                        )}
                    </FormSection>
                </div>

                {/* Right rail */}
                <div className="flex min-w-0 flex-col gap-6 lg:sticky lg:top-6">
                    <SummaryCard
                        quote={quote}
                        fetching={quoteQuery.isFetching}
                        problem={quoteError?.problem.detail ?? null}
                        previousTotal={order?.totals.grand_total ?? null}
                        coupon={
                            mode.kind === 'create' ? (
                                <Field label={t('orders.compose.couponField')} optional error={errors.coupon_code ?? blocked('coupon_code')}>
                                    {({ id, describedBy, invalid }) => (
                                        <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} size="sm" autoComplete="off" value={form.coupon} onChange={(e) => set('coupon', e.target.value.toUpperCase())} className="t-admin-mono" />
                                    )}
                                </Field>
                            ) : order?.totals.coupon_code ? (
                                <p className="t-admin-caption text-fg-secondary">{t('orders.edit.couponKept', { code: order.totals.coupon_code })}</p>
                            ) : null
                        }
                    />
                    <PaymentCard total={quote?.grand_total ?? null} />
                    <div className="flex flex-col gap-2">
                        <Button type="submit" size="lg" className="w-full" disabled={!ready || pending} aria-busy={pending || undefined}>
                            <CheckIcon size={20} aria-hidden />
                            {pending
                                ? t(mode.kind === 'create' ? 'orders.compose.creating' : 'orders.edit.saving')
                                : t(mode.kind === 'create' ? 'orders.compose.create' : 'orders.edit.save', { total: quote ? takaText(quote.grand_total) : '—' })}
                        </Button>
                        {mode.kind === 'create' && <p className="text-center t-admin-caption text-fg-secondary">{t('orders.compose.createNote')}</p>}
                    </div>
                </div>
            </div>
        </form>
    );

    function label(place: { name: string; name_bn: string }): string {
        return locale === 'bn' ? place.name_bn : place.name;
    }
}

function CustomerCard({ loading, customer, phone }: { loading: boolean; customer: NonNullable<ReturnType<typeof useCustomerLookup>['data']>['customer'] | null; phone: string }) {
    const { t } = useI18n();
    if (loading) {
        return (
            <p className="rounded-md bg-subtle px-4 py-3 t-admin-caption text-fg-secondary" aria-busy="true">
                {t('orders.compose.looking')}
            </p>
        );
    }
    if (!customer) {
        return (
            <div className="flex flex-col gap-0.5 rounded-md bg-subtle px-4 py-3" aria-live="polite">
                <p className="t-admin-body-strong text-fg-primary">{t('orders.compose.newCustomer', { phone })}</p>
                <p className="t-admin-caption text-fg-secondary">{t('orders.compose.newCustomerBody')}</p>
            </div>
        );
    }
    return (
        <div className="flex items-center gap-3 rounded-md bg-subtle px-4 py-3" aria-live="polite">
            <span aria-hidden className="grid size-9 shrink-0 place-items-center rounded-full bg-surface t-admin-caption-strong text-fg-primary">
                {initials(customer.name)}
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <p className="t-admin-body-strong text-fg-primary">{t('orders.compose.returning', { name: customer.name })}</p>
                <p className="t-admin-caption text-fg-secondary tabular">
                    {t('orders.compose.returningLine', { orders: customer.orders_count, delivered: customer.delivered_orders_count, spent: takaText(customer.total_spent) })}
                </p>
            </div>
            <ShieldCheckIcon size={18} aria-hidden className="shrink-0 text-feedback-success" />
        </div>
    );
}

function ProductsSection({
    lines,
    info,
    quote,
    error,
    onAdd,
    onQuantity,
    onRemove,
}: {
    lines: Line[];
    info: Record<number, LineInfo>;
    quote: Quote | undefined;
    error: string | undefined;
    onAdd: (v: ComposerVariant) => void;
    onQuantity: (variantId: number, quantity: number) => void;
    onRemove: (variantId: number) => void;
}) {
    const { t, locale } = useI18n();
    const [term, setTerm] = useState('');
    const debounced = useDebounced(term);
    const search = useVariantSearch(debounced);
    const listId = useId();
    const results = term.trim().length >= 2 ? (search.data ?? []) : [];
    const quoted = new Map((quote?.lines ?? []).map((l) => [l.variant_id, l]));

    return (
        <FormSection
            title={t('orders.compose.products')}
            aside={
                <Soon label={t('orders.compose.soon', { feature: t('orders.compose.browse') })}>
                    {(id) => (
                        <SoonButton variant="ghost" size="sm" describedBy={id}>
                            <PackageIcon size={16} aria-hidden />
                            {t('orders.compose.browse')}
                        </SoonButton>
                    )}
                </Soon>
            }
        >
            <div className="relative">
                <Input
                    aria-label={t('orders.compose.search')}
                    aria-controls={listId}
                    aria-expanded={results.length > 0}
                    role="combobox"
                    placeholder={t('orders.compose.search')}
                    leading={<MagnifyingGlassIcon size={16} aria-hidden />}
                    value={term}
                    onChange={(e) => setTerm(e.target.value)}
                    className="bg-subtle"
                />
                {term.trim().length >= 2 && (
                    <ul id={listId} role="listbox" className="absolute inset-x-0 top-full z-20 mt-1 max-h-80 overflow-auto rounded-md border border-line-default bg-surface-raised py-1 shadow-e3">
                        {results.length === 0 && !search.isFetching && <li className="px-3 py-2 t-admin-caption text-fg-secondary">{t('orders.compose.searchEmpty', { term: term.trim() })}</li>}
                        {results.map((v) => (
                            <li key={v.variant_id} role="option" aria-selected={false}>
                                <button
                                    type="button"
                                    aria-label={t('orders.compose.add', { name: v.product_name })}
                                    onClick={() => {
                                        onAdd(v);
                                        setTerm('');
                                    }}
                                    disabled={v.available === 0}
                                    className="flex w-full items-center gap-3 px-3 py-2 text-start hover:bg-subtle disabled:cursor-not-allowed disabled:text-fg-disabled"
                                >
                                    <Thumb src={v.image_url} />
                                    <span className="flex min-w-0 flex-1 flex-col">
                                        <span className="truncate t-admin-body-strong">{locale === 'bn' && v.product_name_bn ? v.product_name_bn : v.product_name}</span>
                                        <span className="t-admin-caption text-fg-secondary">
                                            {v.sku} · {t('orders.compose.available', { count: v.available })}
                                        </span>
                                    </span>
                                    <span className="t-admin-num text-fg-primary">
                                        <Taka amount={v.price} />
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {lines.length === 0 ? (
                <p className="t-admin-caption text-fg-tertiary">{t('orders.compose.noLines')}</p>
            ) : (
                <ul className="flex flex-col border-t border-line-default">
                    {lines.map((line) => {
                        const q = quoted.get(line.variant_id);
                        const meta = info[line.variant_id];
                        const name = q ? (locale === 'bn' && q.product_name_bn ? q.product_name_bn : q.product_name) : (meta?.name ?? `#${line.variant_id}`);
                        const available = q?.available ?? meta?.available ?? null;
                        return (
                            <li key={line.variant_id} className="flex flex-wrap items-center gap-3 border-b border-line-default py-3">
                                <Thumb src={meta?.image ?? null} />
                                <div className="flex min-w-0 flex-1 basis-40 flex-col gap-0.5">
                                    <span className="truncate t-admin-body-strong text-fg-primary">{name}</span>
                                    <span className="t-admin-caption text-fg-secondary">
                                        {q?.sku ?? meta?.sku}
                                        {available !== null && ` · ${t('orders.compose.available', { count: available })}`}
                                    </span>
                                </div>
                                <span className="t-admin-num text-fg-primary">
                                    <Taka amount={q?.unit_price ?? meta?.price ?? '0'} />
                                </span>
                                <div className="flex h-8 items-center rounded-sm border border-line-default">
                                    <button type="button" aria-label={t('orders.compose.decrease', { name })} disabled={line.quantity <= 1} onClick={() => onQuantity(line.variant_id, line.quantity - 1)} className="grid size-8 place-items-center text-fg-secondary hover:text-fg-primary disabled:text-fg-disabled">
                                        <MinusIcon size={14} aria-hidden />
                                    </button>
                                    <input
                                        aria-label={t('orders.compose.quantity', { name })}
                                        inputMode="numeric"
                                        value={line.quantity}
                                        onChange={(e) => {
                                            const n = Number(e.target.value.replace(/\D/g, ''));
                                            if (n > 0) onQuantity(line.variant_id, n);
                                        }}
                                        className="h-full w-10 border-x border-line-default bg-transparent text-center t-admin-num text-fg-primary outline-none"
                                    />
                                    <button type="button" aria-label={t('orders.compose.increase', { name })} disabled={line.quantity >= 99} onClick={() => onQuantity(line.variant_id, line.quantity + 1)} className="grid size-8 place-items-center text-fg-secondary hover:text-fg-primary disabled:text-fg-disabled">
                                        <PlusIcon size={14} aria-hidden />
                                    </button>
                                </div>
                                <span className="w-20 text-end t-admin-body-strong tabular text-fg-primary">{q ? <Taka amount={q.total} /> : '—'}</span>
                                <button type="button" aria-label={t('orders.compose.remove', { name })} onClick={() => onRemove(line.variant_id)} className="grid size-8 place-items-center rounded-sm text-fg-secondary hover:bg-subtle hover:text-feedback-danger">
                                    <TrashIcon size={16} aria-hidden />
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
            {error && (
                <p className="flex items-start gap-1 t-admin-caption text-fg-primary" role="alert">
                    <WarningIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                    {error}
                </p>
            )}
        </FormSection>
    );
}

function Thumb({ src }: { src: string | null }) {
    return src ? (
        <img src={src} alt="" className="size-10 shrink-0 rounded-sm border border-line-default object-cover" />
    ) : (
        <span aria-hidden className="grid size-10 shrink-0 place-items-center rounded-sm bg-subtle text-fg-tertiary">
            <PackageIcon size={18} />
        </span>
    );
}

function SummaryCard({ quote, fetching, problem, previousTotal, coupon }: { quote: Quote | undefined; fetching: boolean; problem: string | null; previousTotal: string | null; coupon: ReactNode }) {
    const { t } = useI18n();
    const row = (labelText: string, amount: ReactNode, key: string) => (
        <div key={key} className="flex items-baseline justify-between gap-3 t-admin-body">
            <span className="text-fg-secondary">{labelText}</span>
            <span className="t-admin-num text-fg-primary">{amount}</span>
        </div>
    );
    const zone = quote?.delivery?.zone ? t(`orders.compose.zone.${quote.delivery.zone}` as MessageKey) : null;

    return (
        <section aria-labelledby="compose-summary" aria-busy={fetching || undefined} className="flex flex-col gap-3 rounded-md border border-line-default bg-surface p-5 shadow-e1">
            <div className="flex items-baseline justify-between gap-2">
                <h2 id="compose-summary" className="t-admin-section text-fg-primary">
                    {t('orders.compose.summary')}
                </h2>
                {fetching && <span className="t-admin-caption text-fg-tertiary">{t('orders.compose.quoting')}</span>}
            </div>
            {quote ? (
                <>
                    {row(t('orders.compose.subtotal', { count: quote.item_count }), <Taka amount={quote.subtotal} />, 'subtotal')}
                    {quote.promotion_discount !== '0.00' && row(t('orders.compose.promotion'), <Taka amount={`-${quote.promotion_discount}`} />, 'promotion')}
                    {quote.coupon?.applied && row(t('orders.compose.coupon', { code: quote.coupon.code }), <Taka amount={`-${quote.coupon.discount}`} />, 'coupon')}
                    {row(
                        zone && quote.delivery ? t('orders.compose.deliveryWith', { zone, weight: quote.delivery.weight_grams }) : t('orders.compose.delivery'),
                        quote.shipping_charge !== null ? <Taka amount={quote.shipping_charge} /> : <span className="t-admin-caption text-fg-tertiary">{t('orders.compose.deliveryPending')}</span>,
                        'delivery',
                    )}
                    <div className="flex items-baseline justify-between gap-3 border-t border-line-default pt-3">
                        <span className="t-admin-body-strong text-fg-primary">{t('orders.compose.total')}</span>
                        <span className="flex flex-col items-end">
                            <span className="t-admin-num-xl text-fg-primary">
                                <Taka amount={quote.grand_total} signClass="t-admin-currency-num-lg" />
                            </span>
                            {previousTotal !== null && previousTotal !== quote.grand_total && <span className="t-admin-caption text-fg-tertiary">{t('orders.edit.was', { total: takaText(previousTotal) })}</span>}
                        </span>
                    </div>
                </>
            ) : (
                row(t('orders.compose.total'), '—', 'empty')
            )}
            {problem && (
                <p className="flex items-start gap-1 t-admin-caption text-fg-primary" role="alert">
                    <WarningIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                    {problem}
                </p>
            )}
            {coupon}
            <p className="t-admin-caption text-fg-secondary">{t('orders.compose.serverNote')}</p>
        </section>
    );
}

function PaymentCard({ total }: { total: string | null }) {
    const { t } = useI18n();
    const option = (title: string, hint: string, selected: boolean, soon: boolean, key: string) => (
        <div
            key={key}
            className={`flex items-start gap-3 rounded-md border px-4 py-3 ${selected ? 'border-line-focus bg-brand-subtle' : 'border-line-default'} ${soon ? 'text-fg-disabled' : 'text-fg-primary'}`}
            aria-disabled={soon || undefined}
            title={soon ? t('orders.compose.paymentSoon') : undefined}
        >
            <span aria-hidden className={`mt-0.5 grid size-4 shrink-0 place-items-center rounded-full border-2 ${selected ? 'border-line-focus' : 'border-line-strong'}`}>
                {selected && <span className="size-2 rounded-full bg-brand" />}
            </span>
            <span className="flex flex-col gap-0.5">
                <span className="t-admin-body-strong">{title}</span>
                <span className={`t-admin-caption ${soon ? '' : 'text-fg-secondary'}`}>{hint}</span>
            </span>
        </div>
    );

    return (
        <section aria-labelledby="compose-payment" className="flex flex-col gap-3 rounded-md border border-line-default bg-surface p-5 shadow-e1">
            <h2 id="compose-payment" className="t-admin-section text-fg-primary">
                {t('orders.compose.payment')}
            </h2>
            <div role="radiogroup" aria-labelledby="compose-payment" className="flex flex-col gap-2.5">
                <div role="radio" aria-checked="true" tabIndex={0}>
                    {option(t('orders.compose.cod'), t('orders.compose.codHint', { total: total ? takaText(total) : '—' }), true, false, 'cod')}
                </div>
                <div role="radio" aria-checked="false" aria-disabled="true">
                    {option(t('orders.compose.advance'), t('orders.compose.advanceHint'), false, true, 'advance')}
                </div>
                <div role="radio" aria-checked="false" aria-disabled="true">
                    {option(t('orders.compose.paidFull'), t('orders.compose.paidFullHint'), false, true, 'full')}
                </div>
            </div>
            <p className="flex items-start gap-2 t-admin-caption text-fg-tertiary">
                <ClipboardTextIcon size={14} aria-hidden className="mt-px shrink-0" />
                {t('orders.compose.paymentSoon')}
            </p>
        </section>
    );
}

/** Shown per Figma but not built yet: aria-disabled (still focusable, so the tooltip can be read). */
function SoonButton({ variant, size = 'md', describedBy, children }: { variant: 'ghost' | 'outline'; size?: 'sm' | 'md'; describedBy: string; children: ReactNode }) {
    return (
        <Button variant={variant} size={size} aria-disabled="true" aria-describedby={describedBy} onClick={(e) => e.preventDefault()} className="cursor-not-allowed !text-fg-disabled hover:!bg-transparent">
            {children}
        </Button>
    );
}

/** A tooltip on hover and keyboard focus. */
export function Soon({ label, children }: { label: string; children: (describedBy: string) => ReactNode }) {
    const id = useId();
    return (
        <span className="group/soon relative inline-flex">
            {children(id)}
            <span
                id={id}
                role="tooltip"
                className="pointer-events-none invisible absolute top-full right-0 z-20 mt-1 rounded-sm border border-line-default bg-surface-raised px-2 py-1 t-admin-caption whitespace-nowrap text-fg-primary opacity-0 shadow-e2 transition-opacity duration-150 ease-standard group-focus-within/soon:visible group-focus-within/soon:opacity-100 group-hover/soon:visible group-hover/soon:opacity-100"
            >
                {label}
            </span>
        </span>
    );
}
