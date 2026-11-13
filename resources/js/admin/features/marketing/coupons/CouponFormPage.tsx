import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { MagicWandIcon, PercentIcon, MoneyIcon, TruckIcon, CopyIcon, DownloadSimpleIcon } from '@phosphor-icons/react';
import { generateCouponsRequestSchema, storeCouponRequestSchema } from '@toymart/api-types/zod';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button, buttonClass } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { ApiError } from '@/lib/api';
import { useCoupon, useCouponReport, useDeactivateCoupon, useGenerateCoupons, useSaveCoupon, type Coupon, type CouponInput, type CouponType, type GenerateInput, type GenerateResult } from '../api';
import {
    BuilderLayout,
    MarketingTabs,
    ScopeEditor,
    SummaryCard,
    TAKA,
    ToggleRow,
    TypeTiles,
    UsageReport,
    card,
    crumbs,
    dhakaDateTime,
    emptyScope,
    fieldErrors,
    fromDhakaInput,
    intOrNull,
    money,
    offerText,
    toDhakaInput,
    type ScopeDraft,
} from '../parts';

type Draft = {
    code: string;
    description: string;
    type: CouponType;
    value: string;
    max_discount: string;
    min_order_amount: string;
    usage_limit_total: string;
    usage_limit_per_customer: string;
    starts_at: string;
    ends_at: string;
    scope: ScopeDraft;
    first_order_only: boolean;
    is_active: boolean;
};

type GenDraft = { count: string; prefix: string; code_length: string; batch: string };

function toDraft(c?: Coupon): Draft {
    return {
        code: c?.code ?? '',
        description: c?.description ?? '',
        type: c?.type ?? 'percent',
        value: c ? c.value.replace(/\.00$/, '') : '',
        max_discount: c?.max_discount ?? '',
        min_order_amount: c && c.min_order_amount !== '0.00' ? c.min_order_amount : '',
        usage_limit_total: c?.usage_limit_total?.toString() ?? '',
        usage_limit_per_customer: c?.usage_limit_per_customer?.toString() ?? '1',
        starts_at: toDhakaInput(c?.starts_at),
        ends_at: toDhakaInput(c?.ends_at),
        scope: c
            ? {
                  applies_to: c.applies_to,
                  ids: c.scope_ids,
                  exclude_product_ids: c.excludes.product_ids ?? [],
                  exclude_category_ids: c.excludes.category_ids ?? [],
                  exclude_brand_ids: c.excludes.brand_ids ?? [],
              }
            : emptyScope,
        first_order_only: c?.first_order_only ?? false,
        is_active: c?.is_active ?? true,
    };
}

/** The terms shared by a single coupon and a bulk batch — the generated request type (rule 27). */
function terms(d: Draft): Omit<CouponInput, 'code'> {
    return {
        description: d.description.trim() || null,
        type: d.type,
        value: d.type === 'free_shipping' ? '0' : (money(d.value) ?? ''),
        max_discount: d.type === 'percent' ? money(d.max_discount) : null,
        min_order_amount: money(d.min_order_amount),
        usage_limit_total: intOrNull(d.usage_limit_total),
        usage_limit_per_customer: intOrNull(d.usage_limit_per_customer),
        starts_at: fromDhakaInput(d.starts_at),
        ends_at: fromDhakaInput(d.ends_at),
        applies_to: d.scope.applies_to,
        scope_ids: d.scope.applies_to === 'all' ? [] : d.scope.ids,
        excludes: { product_ids: d.scope.exclude_product_ids, category_ids: d.scope.exclude_category_ids, brand_ids: d.scope.exclude_brand_ids },
        first_order_only: d.first_order_only,
        is_active: d.is_active,
    };
}

export function toCouponInput(d: Draft): CouponInput {
    return { code: d.code.trim().toUpperCase(), ...terms(d) };
}

export function toGenerateInput(d: Draft, g: GenDraft): GenerateInput {
    return {
        ...terms(d),
        count: intOrNull(g.count) ?? 0,
        prefix: g.prefix.trim().toUpperCase() || null,
        code_length: intOrNull(g.code_length) ?? 8,
        batch: g.batch.trim().toUpperCase(),
    };
}

/** Zod issues → `{field: message}` — the generated schema's paths match the API's field names. */
function zodErrors(issues: { path: PropertyKey[]; message: string }[]): Record<string, string> {
    return Object.fromEntries(issues.map((i) => [i.path.map(String).join('.'), i.message]));
}

const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

/** A suggestion for the code field only (not money). The server still checks it is unique. */
function suggestCode(): string {
    const bytes = new Uint8Array(8);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, (b) => ALPHABET[b % ALPHABET.length]).join('');
}

/**
 * The coupon builder (Figma B · 20 241:42268): Code & type, Conditions, Limits & schedule on
 * the left; the summary ticket and the usage report on the right. With `mode="generate"` the
 * same terms produce a batch of unique codes (POST /coupons/generate). Times are entered as
 * Dhaka wall time and sent with +06:00. Validation: the generated Zod schema, then the
 * server's 422 on the same fields.
 */
export function CouponFormPage({ mode = 'single' }: { mode?: 'single' | 'generate' }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const navigate = useNavigate();
    const me = useMe();
    const manage = can(me.data, 'marketing.manage');
    const params = useParams();
    const id = params.couponId ? Number(params.couponId) : null;
    const coupon = useCoupon(id);
    const report = useCouponReport(id);
    const save = useSaveCoupon(id);
    const generate = useGenerateCoupons();
    const deactivate = useDeactivateCoupon();
    const [draft, setDraft] = useState<Draft>(toDraft());
    const [gen, setGen] = useState<GenDraft>({ count: '100', prefix: '', code_length: '8', batch: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [result, setResult] = useState<GenerateResult | null>(null);
    const [confirm, setConfirm] = useState(false);

    useEffect(() => {
        if (coupon.data) setDraft(toDraft(coupon.data));
    }, [coupon.data]);

    if (id !== null && coupon.isError) return <QueryError error={coupon.error} onRetry={() => void coupon.refetch()} />;

    const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft((d) => ({ ...d, [key]: value }));
    const text = (key: 'code' | 'description' | 'value' | 'max_discount' | 'min_order_amount' | 'usage_limit_total' | 'usage_limit_per_customer' | 'starts_at' | 'ends_at') => ({
        value: draft[key],
        onChange: (e: React.ChangeEvent<HTMLInputElement>) => set(key, e.target.value),
        disabled: !manage,
    });
    const onApiError = (e: unknown) => {
        if (e instanceof ApiError && e.status === 422) setErrors(fieldErrors(e.problem.errors));
        else notify(e instanceof ApiError ? (e.problem.detail ?? e.problem.title) : t('mkt.error'), 'danger');
    };

    const submit = () => {
        if (mode === 'generate') {
            const input = toGenerateInput(draft, gen);
            const parsed = generateCouponsRequestSchema.safeParse(input);
            if (!parsed.success) return setErrors(zodErrors(parsed.error.issues));
            generate.mutate(input, { onSuccess: (r) => (setErrors({}), setResult(r), notify(t('mkt.gen.done', { count: r.count, batch: r.batch }))), onError: onApiError });
            return;
        }
        const input = toCouponInput(draft);
        const parsed = storeCouponRequestSchema.safeParse(input);
        if (!parsed.success) return setErrors(zodErrors(parsed.error.issues));
        save.mutate(input, {
            onSuccess: (c) => {
                setErrors({});
                notify(t('mkt.saved'));
                if (id === null) navigate(`/marketing/coupons/${c.id}`, { replace: true });
            },
            onError: onApiError,
        });
    };

    const title = mode === 'generate' ? t('mkt.gen.title') : id === null ? t('mkt.coupon.newTitle') : t('mkt.coupon.editTitle', { code: coupon.data?.code ?? '' });
    const typeOptions = [
        { value: 'percent' as const, label: t('mkt.coupon.type.percent'), icon: PercentIcon },
        { value: 'fixed' as const, label: t('mkt.coupon.type.fixed'), icon: MoneyIcon },
        { value: 'free_shipping' as const, label: t('mkt.coupon.type.free_shipping'), icon: TruckIcon },
    ];
    const pending = save.isPending || generate.isPending;
    const echo = { type: draft.type, value: money(draft.value) ?? '0', min_order_amount: money(draft.min_order_amount), max_discount: money(draft.max_discount) };
    const facts = [
        draft.first_order_only ? t('mkt.summary.firstOrder') : t('mkt.summary.everyone'),
        intOrNull(draft.usage_limit_per_customer) ? t('mkt.summary.perPhone', { count: intOrNull(draft.usage_limit_per_customer) ?? 0 }) : t('mkt.summary.anyPerPhone'),
        [
            draft.ends_at ? t('mkt.summary.window', { from: dhakaDateTime(fromDhakaInput(draft.starts_at), locale), to: dhakaDateTime(fromDhakaInput(draft.ends_at), locale) }) : t('mkt.summary.noWindow'),
            intOrNull(draft.usage_limit_total) ? t('mkt.summary.uses', { count: intOrNull(draft.usage_limit_total) ?? 0 }) : null,
        ]
            .filter(Boolean)
            .join(' · '),
        t('mkt.summary.scope', { scope: t(`mkt.scope.${draft.scope.applies_to}` as MessageKey) }),
    ];

    return (
        <div className="flex flex-col gap-6">
            <ContentHeader
                title={title}
                breadcrumb={crumbs(t, 'mkt.tab.coupons')}
                subtitle={mode === 'generate' ? t('mkt.gen.subtitle') : t('mkt.coupons.subtitle')}
                actions={
                    manage && (
                        <>
                            {id !== null && coupon.data?.is_active && (
                                <Button variant="outline" onClick={() => setConfirm(true)}>
                                    {t('mkt.deactivate')}
                                </Button>
                            )}
                            <Link to="/marketing/coupons" className={buttonClass('ghost')}>
                                {t('mkt.cancel')}
                            </Link>
                            <Button onClick={submit} disabled={pending}>
                                {mode === 'generate' ? t('mkt.gen.submit') : id === null ? t('mkt.saveActivate') : t('mkt.save')}
                            </Button>
                        </>
                    )
                }
            />
            <MarketingTabs />
            <BuilderLayout
                main={
                    <form
                        className="flex flex-col gap-6"
                        noValidate
                        onSubmit={(e) => {
                            e.preventDefault();
                            submit();
                        }}
                    >
                        <section className={card} aria-labelledby="mkt-code">
                            <h2 id="mkt-code" className="t-admin-section text-fg-primary">
                                {mode === 'generate' ? t('mkt.gen.codes') : t('mkt.coupon.codeType')}
                            </h2>
                            {mode === 'single' ? (
                                <Field label={t('mkt.coupon.code')} hint={t('mkt.coupon.codeHint')} error={errors.code} required>
                                    {({ id: fid, describedBy, invalid }) => (
                                        <div className="flex gap-3">
                                            <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="flex-1 uppercase" maxLength={40} {...text('code')} />
                                            {manage && (
                                                <Button type="button" variant="outline" onClick={() => set('code', suggestCode())}>
                                                    <MagicWandIcon size={16} aria-hidden />
                                                    {t('mkt.coupon.suggest')}
                                                </Button>
                                            )}
                                        </div>
                                    )}
                                </Field>
                            ) : (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field label={t('mkt.gen.batch')} hint={t('mkt.gen.batchHint')} error={errors.batch} required>
                                        {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="uppercase" maxLength={40} value={gen.batch} onChange={(e) => setGen((g) => ({ ...g, batch: e.target.value }))} />}
                                    </Field>
                                    <Field label={t('mkt.gen.count')} error={errors.count} required>
                                        {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} type="number" min={1} max={1000} className="tabular" value={gen.count} onChange={(e) => setGen((g) => ({ ...g, count: e.target.value }))} />}
                                    </Field>
                                    <Field label={t('mkt.gen.prefix')} hint={t('mkt.gen.prefixHint')} error={errors.prefix} optional>
                                        {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="uppercase" maxLength={12} value={gen.prefix} onChange={(e) => setGen((g) => ({ ...g, prefix: e.target.value }))} />}
                                    </Field>
                                    <Field label={t('mkt.gen.length')} error={errors.code_length}>
                                        {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} type="number" min={6} max={16} className="tabular" value={gen.code_length} onChange={(e) => setGen((g) => ({ ...g, code_length: e.target.value }))} />}
                                    </Field>
                                </div>
                            )}
                            <TypeTiles label={t('mkt.coupon.typeLabel')} options={typeOptions} value={draft.type} onChange={(v) => manage && set('type', v)} />
                            {draft.type !== 'free_shipping' && (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field label={t('mkt.coupon.discount')} error={errors.value} required>
                                        {({ id: fid, describedBy, invalid }) => (
                                            <Input
                                                id={fid}
                                                aria-describedby={describedBy}
                                                aria-invalid={invalid || undefined}
                                                inputMode="decimal"
                                                className="tabular"
                                                prefix={draft.type === 'fixed' ? TAKA : undefined}
                                                suffix={draft.type === 'percent' ? '%' : undefined}
                                                {...text('value')}
                                            />
                                        )}
                                    </Field>
                                    {draft.type === 'percent' && (
                                        <Field label={t('mkt.coupon.maxDiscount')} hint={t('mkt.coupon.maxDiscountHint')} error={errors.max_discount} optional>
                                            {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} inputMode="decimal" className="tabular" prefix={TAKA} {...text('max_discount')} />}
                                        </Field>
                                    )}
                                </div>
                            )}
                            <Field label={t('mkt.coupon.description')} error={errors.description} optional>
                                {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} maxLength={255} {...text('description')} />}
                            </Field>
                        </section>

                        <section className={card} aria-labelledby="mkt-conditions">
                            <h2 id="mkt-conditions" className="t-admin-section text-fg-primary">
                                {t('mkt.coupon.conditions')}
                            </h2>
                            <Field label={t('mkt.coupon.minOrder')} hint={t('mkt.coupon.minOrderHint')} error={errors.min_order_amount} optional>
                                {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} inputMode="decimal" className="tabular" prefix={TAKA} {...text('min_order_amount')} />}
                            </Field>
                            <ScopeEditor value={draft.scope} onChange={(scope) => set('scope', scope)} errors={{ ids: errors.scope_ids }} />
                            <ToggleRow label={t('mkt.coupon.firstOrder')} checked={draft.first_order_only} onChange={(v) => set('first_order_only', v)} disabled={!manage} />
                            <ToggleRow label={t('mkt.coupon.active')} checked={draft.is_active} onChange={(v) => set('is_active', v)} disabled={!manage} />
                        </section>

                        <section className={card} aria-labelledby="mkt-limits">
                            <h2 id="mkt-limits" className="t-admin-section text-fg-primary">
                                {t('mkt.coupon.limits')}
                            </h2>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label={t('mkt.coupon.totalUses')} hint={mode === 'generate' ? t('mkt.gen.perCodeNote') : undefined} error={errors.usage_limit_total} optional>
                                    {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} type="number" min={1} className="tabular" {...text('usage_limit_total')} />}
                                </Field>
                                <Field label={t('mkt.coupon.perCustomer')} hint={t('mkt.coupon.perCustomerHint')} error={errors.usage_limit_per_customer} optional>
                                    {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} type="number" min={1} className="tabular" {...text('usage_limit_per_customer')} />}
                                </Field>
                                <Field label={t('mkt.coupon.starts')} hint={t('mkt.dhakaTime')} error={errors.starts_at} optional>
                                    {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} type="datetime-local" className="tabular" {...text('starts_at')} />}
                                </Field>
                                <Field label={t('mkt.coupon.ends')} hint={t('mkt.dhakaTime')} error={errors.ends_at} optional>
                                    {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} type="datetime-local" className="tabular" {...text('ends_at')} />}
                                </Field>
                            </div>
                        </section>
                        <button type="submit" hidden />
                    </form>
                }
                aside={
                    <>
                        <SummaryCard
                            ticket={mode === 'generate' ? `${gen.prefix ? `${gen.prefix.toUpperCase()}-` : ''}${'X'.repeat(intOrNull(gen.code_length) ?? 8)}` : draft.code.toUpperCase() || '—'}
                            ticketSub={offerText(t, echo)}
                            facts={facts}
                            note={t('mkt.serverNote')}
                        />
                        {result && <GeneratedCodes result={result} />}
                        {id !== null && report.data && <UsageReport summary={report.data.summary} batch={report.data.batch} recent={report.data.recent} />}
                    </>
                }
            />
            <ConfirmDialog
                open={confirm}
                title={t('mkt.deactivateTitle', { name: coupon.data?.code ?? '' })}
                body={t('mkt.deactivateBody')}
                confirmLabel={t('mkt.deactivate')}
                pending={deactivate.isPending}
                onClose={() => setConfirm(false)}
                onConfirm={() =>
                    id !== null &&
                    deactivate.mutate(id, {
                        onSuccess: () => (setConfirm(false), notify(t('mkt.deactivated'))),
                        onError: onApiError,
                    })
                }
            />
        </div>
    );
}

/** The batch the server created: every code, with copy and a CSV download (built from the server's list). */
function GeneratedCodes({ result }: { result: GenerateResult }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const csv = `code,batch\n${result.codes.map((c) => `${c},${result.batch}`).join('\n')}\n`;
    return (
        <section className={card} aria-labelledby="mkt-generated">
            <h2 id="mkt-generated" className="t-admin-section text-fg-primary">
                {t('mkt.gen.done', { count: result.count, batch: result.batch })}
            </h2>
            <ul className="max-h-60 overflow-auto rounded-sm border border-line-default bg-subtle p-2 t-admin-mono-sm text-fg-primary">
                {result.codes.map((c) => (
                    <li key={c}>{c}</li>
                ))}
            </ul>
            <div className="flex flex-wrap gap-2">
                <Button type="button" variant="outline" size="sm" onClick={() => void navigator.clipboard?.writeText(result.codes.join('\n')).then(() => notify(t('mkt.gen.copied')))}>
                    <CopyIcon size={16} aria-hidden />
                    {t('mkt.gen.copy')}
                </Button>
                <a href={`data:text/csv;charset=utf-8,${encodeURIComponent(csv)}`} download={`${result.batch}.csv`} className={buttonClass('outline', 'sm')}>
                    <DownloadSimpleIcon size={16} aria-hidden />
                    {t('mkt.gen.csv')}
                </a>
                <Link to={`/marketing/coupons?batch=${encodeURIComponent(result.batch)}`} className={buttonClass('ghost', 'sm')}>
                    {t('mkt.coupons.batchTag', { batch: result.batch })}
                </Link>
            </div>
        </section>
    );
}
