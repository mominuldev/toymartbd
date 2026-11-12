import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { storeSupplierRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Field, inputClass } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { useArchiveSupplier, useSaveSupplier, useSupplier, type Supplier, type SupplierInput } from './api';
import { TAKA } from './parts';

type Values = {
    name: string;
    contact_person: string;
    phone: string;
    alt_phone: string;
    email: string;
    address: string;
    tin: string;
    payment_terms_days: string;
    opening_balance: string;
    note: string;
    active: boolean;
};

function toValues(s?: Supplier): Values {
    // E.164 back to the 11-digit local form staff type (rule 30 storage, local entry).
    const local = (phone: string | null | undefined) => (phone ? phone.replace(/^\+880/, '0') : '');
    return {
        name: s?.name ?? '',
        contact_person: s?.contact_person ?? '',
        phone: local(s?.phone),
        alt_phone: local(s?.alt_phone),
        email: s?.email ?? '',
        address: s?.address ?? '',
        tin: s?.tin ?? '',
        payment_terms_days: String(s?.payment_terms_days ?? 0),
        opening_balance: s?.balance?.opening ?? '0',
        note: s?.note ?? '',
        active: (s?.status ?? 'active') === 'active',
    };
}

function toInput(v: Values, editing: boolean, showOpening: boolean): SupplierInput {
    const orNull = (x: string) => (x.trim() === '' ? null : x.trim());
    return {
        name: v.name.trim(),
        contact_person: orNull(v.contact_person),
        phone: orNull(v.phone),
        alt_phone: orNull(v.alt_phone),
        email: orNull(v.email),
        address: orNull(v.address),
        tin: orNull(v.tin),
        payment_terms_days: /^\d+$/.test(v.payment_terms_days.trim()) ? Number(v.payment_terms_days.trim()) : Number.NaN,
        ...(showOpening ? { opening_balance: v.opening_balance.trim().replace(/,/g, '') || '0' } : {}),
        note: orNull(v.note),
        ...(editing ? { status: v.active ? 'active' : 'archived' } : {}),
    };
}

/**
 * Create / edit a supplier (I-03; not drawn — follows the brand form's FormSection, AdminField
 * 185:151 and FormActions 198:1085). Phones are typed locally (01XXXXXXXXX) and stored E.164
 * by the server, which also enforces the 013–019 prefixes (rule 30). Client validation is the
 * generated schema (rule 27).
 */
export function SupplierFormPage() {
    const params = useParams();
    const id = params.supplierId ? Number(params.supplierId) : null;
    const supplier = useSupplier(id);

    if (id !== null && supplier.isError) return <QueryError error={supplier.error} onRetry={() => void supplier.refetch()} />;
    if (id !== null && !supplier.data) return <p aria-busy="true" className="sr-only" />;

    return <SupplierForm key={supplier.data?.id ?? 'new'} id={id} supplier={supplier.data} />;
}

function SupplierForm({ id, supplier }: { id: number | null; supplier?: Supplier }) {
    const { t } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const navigate = useNavigate();
    const save = useSaveSupplier(id);
    const archive = useArchiveSupplier(id ?? 0);
    const [values, setValues] = useState<Values>(() => toValues(supplier));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [confirming, setConfirming] = useState(false);
    const editable = can(me, 'purchasing.manage_suppliers');
    // The opening balance is a payable figure: entered at creation, or edited by finance staff.
    const showOpening = id === null || can(me, 'finance.view');
    const set = (key: keyof Values, value: string | boolean) => setValues((v) => ({ ...v, [key]: value }));

    function submit() {
        const input = toInput(values, id !== null, showOpening);
        const parsed = storeSupplierRequestSchema.safeParse(input);
        if (!parsed.success) {
            const next: Record<string, string> = {};
            for (const issue of parsed.error.issues) next[issue.path.join('.')] ??= issue.message;
            setErrors(next);
            return;
        }
        setErrors({});
        save.mutate(input, {
            onSuccess: (saved) => {
                notify(t(id === null ? 'suppliers.created' : 'suppliers.saved'));
                navigate(`/purchasing/suppliers/${saved.id}`, { replace: true });
            },
            onError: (error) => {
                if (error instanceof ApiError && error.status === 422) {
                    setErrors(Object.fromEntries((error.problem.errors ?? []).map((e) => [e.field, e.message])));
                } else {
                    notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger');
                }
            },
        });
    }

    const text = (key: keyof Values, label: MessageKey, opts: { hint?: MessageKey; optional?: boolean; mono?: boolean; inputMode?: 'tel' | 'email' | 'numeric' | 'decimal'; prefix?: boolean } = {}) => (
        <Field label={t(label)} hint={opts.hint ? t(opts.hint) : undefined} error={errors[key]} optional={opts.optional} required={!opts.optional}>
            {({ id: fid, describedBy, invalid }) => (
                <Input
                    id={fid}
                    aria-describedby={describedBy}
                    aria-invalid={invalid || undefined}
                    inputMode={opts.inputMode}
                    prefix={opts.prefix ? <span className="t-admin-currency-num">{TAKA}</span> : undefined}
                    className={opts.mono ? 't-admin-mono tabular' : ''}
                    value={String(values[key])}
                    onChange={(e) => set(key, e.target.value)}
                />
            )}
        </Field>
    );

    const title = id === null ? t('suppliers.new') : t('suppliers.edit');

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('purchasing.crumb'), to: '/purchasing' }, { label: t('suppliers.title'), to: '/purchasing/suppliers' }, { label: title }]}
                title={title}
            />
            <form
                noValidate
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
                className="flex flex-col gap-6"
            >
                <fieldset disabled={!editable || save.isPending} className="flex w-full min-w-0 max-w-3xl flex-col gap-3.5 rounded-md border border-line-default bg-surface p-5 shadow-e1">
                    <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                        {text('name', 'suppliers.field.name')}
                        {text('contact_person', 'suppliers.field.contact', { optional: true })}
                        {text('phone', 'suppliers.field.phone', { optional: true, mono: true, inputMode: 'tel', hint: 'suppliers.field.phoneHint' })}
                        {text('alt_phone', 'suppliers.field.altPhone', { optional: true, mono: true, inputMode: 'tel' })}
                        {text('email', 'suppliers.field.email', { optional: true, inputMode: 'email' })}
                        {text('tin', 'suppliers.field.tin', { optional: true, mono: true })}
                        {text('payment_terms_days', 'suppliers.field.terms', { inputMode: 'numeric', hint: 'suppliers.field.termsHint' })}
                        {showOpening && text('opening_balance', 'suppliers.field.opening', { inputMode: 'decimal', prefix: true, hint: 'suppliers.field.openingHint' })}
                    </div>
                    <Field label={t('suppliers.field.address')} error={errors.address} optional>
                        {({ id: fid, describedBy, invalid }) => (
                            <textarea id={fid} rows={2} maxLength={500} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`py-2 ${inputClass}`} value={values.address} onChange={(e) => set('address', e.target.value)} />
                        )}
                    </Field>
                    <Field label={t('suppliers.field.note')} error={errors.note} optional>
                        {({ id: fid, describedBy, invalid }) => (
                            <textarea id={fid} rows={2} maxLength={1000} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`py-2 ${inputClass}`} value={values.note} onChange={(e) => set('note', e.target.value)} />
                        )}
                    </Field>
                    {id !== null && (
                        <label className="flex min-h-touch-min items-center gap-3 t-admin-body text-fg-primary">
                            <input type="checkbox" className="size-4 accent-brand" checked={values.active} onChange={(e) => set('active', e.target.checked)} />
                            {t('suppliers.field.active')}
                        </label>
                    )}
                </fieldset>

                <div className="sticky bottom-0 z-10 -mx-4 -mb-4 flex min-h-16 flex-wrap items-center justify-between gap-3 border-t border-line-default bg-surface px-4 py-3.5 shadow-e4 md:-mx-5 md:-mb-10 md:px-5 xl:-mx-6 xl:px-6">
                    {id !== null && supplier?.status === 'active' && editable ? (
                        <Button variant="danger" onClick={() => setConfirming(true)}>
                            {t('suppliers.archive')}
                        </Button>
                    ) : (
                        <span />
                    )}
                    <div className="flex flex-wrap items-center gap-3">
                        <Link to={id === null ? '/purchasing/suppliers' : `/purchasing/suppliers/${id}`} className={buttonClass('ghost')}>
                            {t('common.cancel')}
                        </Link>
                        {editable && (
                            <Button type="submit" disabled={save.isPending}>
                                {save.isPending ? t('common.saving') : t('common.save')}
                            </Button>
                        )}
                    </div>
                </div>
            </form>

            {supplier && (
                <ConfirmDialog
                    open={confirming}
                    title={t('suppliers.archiveTitle', { name: supplier.name })}
                    body={t('suppliers.archiveBody')}
                    confirmLabel={t('suppliers.archive')}
                    pending={archive.isPending}
                    onConfirm={() =>
                        archive.mutate(undefined, {
                            onSuccess: () => {
                                notify(t('suppliers.archived'));
                                navigate('/purchasing/suppliers', { replace: true });
                            },
                        })
                    }
                    onClose={() => setConfirming(false)}
                />
            )}
        </section>
    );
}
