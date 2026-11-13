import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { GiftIcon, LightningIcon, PackageIcon, PercentIcon, StairsIcon, TrashIcon, TruckIcon, PlusIcon } from '@phosphor-icons/react';
import { storePromotionRequestSchema } from '@toymart/api-types/zod';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button, buttonClass } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { ApiError } from '@/lib/api';
import { useDeactivatePromotion, usePromotion, usePromotionReport, useSavePromotion, useVariantSearch, type Promotion, type PromotionInput, type PromotionType } from '../api';
import {
    BuilderLayout,
    IdPicker,
    MarketingTabs,
    PromotionStatusChip,
    ScopeEditor,
    SummaryCard,
    TAKA,
    ToggleRow,
    TypeTiles,
    UsageReport,
    card,
    crumbs,
    dhakaDateTime,
    fieldErrors,
    fromDhakaInput,
    intOrNull,
    money,
    taka,
    toDhakaInput,
    type ScopeDraft,
} from '../parts';

type Kind = 'percent' | 'fixed' | 'price';
type Tier = { min_subtotal: string; type: 'percent' | 'fixed'; value: string };
type FlashLine = { variant_id: number; label: string; regular_price: string | null; sale_price: string; allotment: string; per_order_limit: string; claimed: number; remaining: number | null };

export type PromotionDraft = {
    name: string;
    name_bn: string;
    description: string;
    type: PromotionType;
    priority: string;
    is_stackable: boolean;
    allows_coupons: boolean;
    is_active: boolean;
    starts_at: string;
    ends_at: string;
    usage_limit_total: string;
    usage_limit_per_customer: string;
    budget: string;
    scope: ScopeDraft;
    kind: Kind;
    value: string;
    max_discount: string;
    tiers: Tier[];
    buy: string;
    get: string;
    percent: string;
    product_ids: number[];
    min_subtotal: string;
    flash_items: FlashLine[];
};

const str = (v: unknown): string => (typeof v === 'string' ? v : typeof v === 'number' ? String(v) : '');
const ints = (v: unknown): number[] => (Array.isArray(v) ? v.filter((x): x is number => typeof x === 'number') : []);

export function toDraft(p?: Promotion, type: PromotionType = 'discount'): PromotionDraft {
    const r = (p?.rules ?? {}) as Record<string, unknown>;
    const scope = (r.scope ?? {}) as Record<string, unknown>;
    const appliesTo = str(scope.applies_to);
    const kind = str(r.type);
    return {
        name: p?.name ?? '',
        name_bn: p?.name_bn ?? '',
        description: p?.description ?? '',
        type: p?.type === 'category_discount' ? 'discount' : (p?.type ?? type),
        priority: String(p?.priority ?? (type === 'flash_sale' ? 100 : 0)),
        is_stackable: p?.is_stackable ?? true,
        allows_coupons: p?.allows_coupons ?? true,
        is_active: p?.is_active ?? true,
        starts_at: toDhakaInput(p?.starts_at),
        ends_at: toDhakaInput(p?.ends_at),
        usage_limit_total: p?.usage_limit_total?.toString() ?? '',
        usage_limit_per_customer: p?.usage_limit_per_customer?.toString() ?? '',
        budget: p?.budget ?? '',
        scope: {
            applies_to: appliesTo === 'products' || appliesTo === 'categories' || appliesTo === 'brands' ? appliesTo : 'all',
            ids: ints(scope.ids),
            exclude_product_ids: ints(scope.exclude_product_ids),
            exclude_category_ids: ints(scope.exclude_category_ids),
            exclude_brand_ids: ints(scope.exclude_brand_ids),
        },
        kind: kind === 'fixed' || kind === 'price' ? kind : 'percent',
        value: str(r.value).replace(/\.00$/, ''),
        max_discount: str(r.max_discount),
        tiers: Array.isArray(r.tiers)
            ? (r.tiers as Record<string, unknown>[]).map((tr) => ({ min_subtotal: str(tr.min_subtotal), type: str(tr.type) === 'fixed' ? 'fixed' : 'percent', value: str(tr.value) }))
            : [{ min_subtotal: '', type: 'percent', value: '' }],
        buy: str(r.buy) || '2',
        get: str(r.get) || '1',
        percent: str(r.percent) || '100',
        product_ids: ints(r.product_ids),
        min_subtotal: str(r.min_subtotal),
        flash_items: (p?.flash_items ?? []).map((i) => ({
            variant_id: i.variant_id,
            label: [i.product_name, i.variant_name, i.sku].filter(Boolean).join(' · '),
            regular_price: i.regular_price,
            sale_price: i.sale_price,
            allotment: String(i.allotment),
            per_order_limit: i.per_order_limit?.toString() ?? '',
            claimed: i.claimed,
            remaining: i.remaining,
        })),
    };
}

/** Draft → the generated request type (rule 27); only the keys this type reads. */
export function toPromotionInput(d: PromotionDraft): PromotionInput {
    const scope = {
        applies_to: d.scope.applies_to,
        ids: d.scope.applies_to === 'all' ? [] : d.scope.ids,
        exclude_product_ids: d.scope.exclude_product_ids,
        exclude_category_ids: d.scope.exclude_category_ids,
        exclude_brand_ids: d.scope.exclude_brand_ids,
    };
    const flash = d.type === 'flash_sale';
    const rules: PromotionInput['rules'] =
        d.type === 'discount' || d.type === 'category_discount'
            ? { scope, type: d.kind === 'price' ? 'percent' : d.kind, value: money(d.value), max_discount: money(d.max_discount) }
            : d.type === 'tiered'
              ? { scope, tiers: d.tiers.map((tr) => ({ min_subtotal: money(tr.min_subtotal) ?? '', type: tr.type, value: money(tr.value) ?? '' })), max_discount: money(d.max_discount) }
              : d.type === 'buy_x_get_y'
                ? { scope, buy: intOrNull(d.buy) ?? 0, get: intOrNull(d.get) ?? 0, percent: Number(d.percent) || 100, max_discount: money(d.max_discount) }
                : d.type === 'bundle'
                  ? { scope, product_ids: d.product_ids, type: d.kind, value: money(d.value) }
                  : d.type === 'free_delivery'
                    ? { scope, min_subtotal: money(d.min_subtotal) ?? '' }
                    : { scope };
    return {
        name: d.name.trim(),
        name_bn: d.name_bn.trim() || null,
        description: d.description.trim() || null,
        type: d.type,
        priority: intOrNull(d.priority) ?? 0,
        is_stackable: d.is_stackable,
        allows_coupons: d.allows_coupons,
        is_active: d.is_active,
        starts_at: fromDhakaInput(d.starts_at),
        ends_at: fromDhakaInput(d.ends_at),
        usage_limit_total: intOrNull(d.usage_limit_total),
        usage_limit_per_customer: intOrNull(d.usage_limit_per_customer),
        budget: money(d.budget),
        rules,
        flash_items: flash
            ? d.flash_items.map((i) => ({ variant_id: i.variant_id, sale_price: money(i.sale_price) ?? '', allotment: intOrNull(i.allotment) ?? 0, per_order_limit: intOrNull(i.per_order_limit) }))
            : [],
    };
}

function zodErrors(issues: { path: PropertyKey[]; message: string }[]): Record<string, string> {
    return Object.fromEntries(issues.map((i) => [i.path.map(String).join('.'), i.message]));
}

/**
 * Promotion and flash-sale builder (no Figma frame — B · 20's builder layout): name & type,
 * the offer for that type, scope, priority & stacking (with the explicit "coupons can also
 * apply" switch), limits/budget/schedule in Dhaka time, flash-sale items with claimed units.
 */
export function PromotionFormPage({ flash = false }: { flash?: boolean }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const navigate = useNavigate();
    const me = useMe();
    const manage = can(me.data, 'marketing.manage');
    const params = useParams();
    const id = params.promotionId ? Number(params.promotionId) : null;
    const promotion = usePromotion(id);
    const report = usePromotionReport(id);
    const save = useSavePromotion(id);
    const deactivate = useDeactivatePromotion();
    const [draft, setDraft] = useState<PromotionDraft>(() => toDraft(undefined, flash ? 'flash_sale' : 'discount'));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [confirm, setConfirm] = useState(false);

    useEffect(() => {
        if (promotion.data) setDraft(toDraft(promotion.data));
    }, [promotion.data]);

    if (id !== null && promotion.isError) return <QueryError error={promotion.error} onRetry={() => void promotion.refetch()} />;

    const set = <K extends keyof PromotionDraft>(key: K, value: PromotionDraft[K]) => setDraft((d) => ({ ...d, [key]: value }));
    const text = (key: 'name' | 'name_bn' | 'description' | 'priority' | 'starts_at' | 'ends_at' | 'usage_limit_total' | 'usage_limit_per_customer' | 'budget' | 'value' | 'max_discount' | 'buy' | 'get' | 'percent' | 'min_subtotal') => ({
        value: draft[key],
        onChange: (e: React.ChangeEvent<HTMLInputElement>) => set(key, e.target.value),
        disabled: !manage,
    });
    const isFlash = draft.type === 'flash_sale';
    const base = isFlash ? '/marketing/flash-sales' : '/marketing/promotions';
    const onApiError = (e: unknown) => {
        if (e instanceof ApiError && e.status === 422) setErrors(fieldErrors(e.problem.errors));
        else notify(e instanceof ApiError ? (e.problem.detail ?? e.problem.title) : t('mkt.error'), 'danger');
    };

    const submit = () => {
        const input = toPromotionInput(draft);
        const parsed = storePromotionRequestSchema.safeParse(input);
        const local: Record<string, string> = parsed.success ? {} : zodErrors(parsed.error.issues);
        if (isFlash && (!input.starts_at || !input.ends_at)) local.ends_at = t('mkt.promo.windowRequired');
        if (Object.keys(local).length > 0) return setErrors(local);
        save.mutate(input, {
            onSuccess: (p) => {
                setErrors({});
                notify(t('mkt.saved'));
                if (id === null) navigate(`${p.type === 'flash_sale' ? '/marketing/flash-sales' : '/marketing/promotions'}/${p.id}`, { replace: true });
            },
            onError: onApiError,
        });
    };

    const typeOptions = (flash || isFlash ? (['flash_sale'] as const) : (['discount', 'tiered', 'buy_x_get_y', 'bundle', 'free_delivery'] as const)).map((v) => ({
        value: v as PromotionType,
        label: t(`mkt.ptype.${v}` as MessageKey),
        icon: { discount: PercentIcon, tiered: StairsIcon, buy_x_get_y: GiftIcon, bundle: PackageIcon, free_delivery: TruckIcon, flash_sale: LightningIcon }[v],
    }));
    const title = id === null ? t(isFlash ? 'mkt.promo.newFlash' : 'mkt.promo.newTitle') : (promotion.data?.name ?? '');
    const kindOptions: Kind[] = draft.type === 'bundle' ? ['percent', 'fixed', 'price'] : ['percent', 'fixed'];
    const kindLabel = (k: Kind) => t(k === 'percent' ? 'mkt.promo.kind.percent' : k === 'price' ? 'mkt.promo.kind.price' : draft.type === 'bundle' ? 'mkt.promo.kind.fixedBundle' : 'mkt.promo.kind.fixed');

    const facts = [
        draft.is_stackable ? t('mkt.promo.stackable') : t('mkt.promos.exclusive'),
        draft.allows_coupons ? t('mkt.promo.allowsCoupons') : t('mkt.promos.noCoupons'),
        draft.ends_at ? t('mkt.summary.window', { from: dhakaDateTime(fromDhakaInput(draft.starts_at), locale), to: dhakaDateTime(fromDhakaInput(draft.ends_at), locale) }) : t('mkt.summary.noWindow'),
        t('mkt.summary.scope', { scope: t(`mkt.scope.${draft.scope.applies_to}` as MessageKey) }),
        intOrNull(draft.usage_limit_per_customer) ? t('mkt.summary.perPhone', { count: intOrNull(draft.usage_limit_per_customer) ?? 0 }) : t('mkt.summary.anyPerPhone'),
    ];

    return (
        <div className="flex flex-col gap-6">
            <ContentHeader
                title={title}
                breadcrumb={crumbs(t, isFlash ? 'mkt.tab.flash' : 'mkt.tab.promotions')}
                subtitle={t(isFlash ? 'mkt.flash.subtitle' : 'mkt.promos.subtitle')}
                titleAddon={promotion.data && <PromotionStatusChip status={promotion.data.status} />}
                actions={
                    manage && (
                        <>
                            {id !== null && promotion.data?.is_active && (
                                <Button variant="outline" onClick={() => setConfirm(true)}>
                                    {t('mkt.deactivate')}
                                </Button>
                            )}
                            <Link to={base} className={buttonClass('ghost')}>
                                {t('mkt.cancel')}
                            </Link>
                            <Button onClick={submit} disabled={save.isPending}>
                                {id === null ? t('mkt.saveActivate') : t('mkt.save')}
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
                        <section className={card} aria-labelledby="mkt-basics">
                            <h2 id="mkt-basics" className="t-admin-section text-fg-primary">
                                {t('mkt.promo.basics')}
                            </h2>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label={t('mkt.promo.name')} hint={t('mkt.promo.nameHint')} error={errors.name} required>
                                    {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} maxLength={120} {...text('name')} />}
                                </Field>
                                <Field label={t('mkt.promo.nameBn')} error={errors.name_bn} optional>
                                    {({ id: fid, describedBy, invalid }) => <Input id={fid} lang="bn" aria-describedby={describedBy} aria-invalid={invalid || undefined} maxLength={120} {...text('name_bn')} />}
                                </Field>
                            </div>
                            {typeOptions.length > 1 && (
                                <TypeTiles
                                    label={t('mkt.promo.type')}
                                    options={typeOptions}
                                    value={draft.type}
                                    onChange={(v) => manage && setDraft((d) => ({ ...d, type: v, kind: v === 'bundle' ? d.kind : d.kind === 'price' ? 'percent' : d.kind }))}
                                />
                            )}
                            <Field label={t('mkt.promo.description')} error={errors.description} optional>
                                {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} maxLength={255} {...text('description')} />}
                            </Field>
                        </section>

                        {isFlash ? (
                            <FlashItemsEditor items={draft.flash_items} onChange={(items) => set('flash_items', items)} errors={errors} disabled={!manage} />
                        ) : (
                            <section className={card} aria-labelledby="mkt-rule">
                                <h2 id="mkt-rule" className="t-admin-section text-fg-primary">
                                    {t('mkt.promo.rule')}
                                </h2>
                                {(draft.type === 'discount' || draft.type === 'bundle') && (
                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <Field label={t('mkt.promo.kind')} error={errors['rules.type']}>
                                            {({ id: fid, describedBy }) => (
                                                <Select id={fid} aria-describedby={describedBy} value={draft.kind} onChange={(e) => set('kind', e.target.value as Kind)} disabled={!manage}>
                                                    {kindOptions.map((k) => (
                                                        <option key={k} value={k}>
                                                            {kindLabel(k)}
                                                        </option>
                                                    ))}
                                                </Select>
                                            )}
                                        </Field>
                                        <Field label={t('mkt.promo.value')} error={errors['rules.value']} required>
                                            {({ id: fid, describedBy, invalid }) => (
                                                <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} inputMode="decimal" className="tabular" prefix={draft.kind === 'percent' ? undefined : TAKA} suffix={draft.kind === 'percent' ? '%' : undefined} {...text('value')} />
                                            )}
                                        </Field>
                                        {draft.type === 'discount' && (
                                            <Field label={t('mkt.promo.maxDiscount')} error={errors['rules.max_discount']} optional>
                                                {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} inputMode="decimal" className="tabular" prefix={TAKA} {...text('max_discount')} />}
                                            </Field>
                                        )}
                                    </div>
                                )}
                                {draft.type === 'bundle' && <IdPicker kind="products" label={t('mkt.promo.bundleProducts')} value={draft.product_ids} onChange={(ids) => set('product_ids', ids)} error={errors['rules.product_ids']} />}
                                {draft.type === 'tiered' && <TiersEditor tiers={draft.tiers} onChange={(tiers) => set('tiers', tiers)} errors={errors} disabled={!manage} />}
                                {draft.type === 'buy_x_get_y' && (
                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <Field label={t('mkt.promo.buy')} error={errors['rules.buy']} required>
                                            {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} type="number" min={1} max={99} className="tabular" {...text('buy')} />}
                                        </Field>
                                        <Field label={t('mkt.promo.get')} error={errors['rules.get']} required>
                                            {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} type="number" min={1} max={99} className="tabular" {...text('get')} />}
                                        </Field>
                                        <Field label={t('mkt.promo.percentOff')} hint={t('mkt.promo.percentOffHint')} error={errors['rules.percent']}>
                                            {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} type="number" min={1} max={100} className="tabular" suffix="%" {...text('percent')} />}
                                        </Field>
                                    </div>
                                )}
                                {draft.type === 'free_delivery' && (
                                    <Field label={t('mkt.promo.minSubtotal')} hint={t('mkt.promo.minSubtotalHint')} error={errors['rules.min_subtotal']} required>
                                        {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} inputMode="decimal" className="tabular" prefix={TAKA} {...text('min_subtotal')} />}
                                    </Field>
                                )}
                                <div className="border-t border-line-default pt-4">
                                    <ScopeEditor value={draft.scope} onChange={(scope) => set('scope', scope)} errors={{ ids: errors['rules.scope.ids'] }} />
                                </div>
                            </section>
                        )}

                        <section className={card} aria-labelledby="mkt-stacking">
                            <h2 id="mkt-stacking" className="t-admin-section text-fg-primary">
                                {t('mkt.promo.stacking')}
                            </h2>
                            <Field label={t('mkt.promo.priority')} hint={t('mkt.promo.priorityHint')} error={errors.priority}>
                                {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} type="number" min={-1000} max={1000} className="max-w-40 tabular" {...text('priority')} />}
                            </Field>
                            <ToggleRow label={t('mkt.promo.stackable')} hint={t('mkt.promo.stackableHint')} checked={draft.is_stackable} onChange={(v) => set('is_stackable', v)} disabled={!manage} />
                            <ToggleRow label={t('mkt.promo.allowsCoupons')} hint={t('mkt.promo.allowsCouponsHint')} checked={draft.allows_coupons} onChange={(v) => set('allows_coupons', v)} disabled={!manage} />
                            <ToggleRow label={t('mkt.promo.active')} checked={draft.is_active} onChange={(v) => set('is_active', v)} disabled={!manage} />
                        </section>

                        <section className={card} aria-labelledby="mkt-limits">
                            <h2 id="mkt-limits" className="t-admin-section text-fg-primary">
                                {t('mkt.promo.limits')}
                            </h2>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Field label={t('mkt.promo.totalUses')} error={errors.usage_limit_total} optional>
                                    {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} type="number" min={1} className="tabular" {...text('usage_limit_total')} />}
                                </Field>
                                <Field label={t('mkt.promo.perCustomer')} hint={t('mkt.coupon.perCustomerHint')} error={errors.usage_limit_per_customer} optional>
                                    {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} type="number" min={1} className="tabular" {...text('usage_limit_per_customer')} />}
                                </Field>
                                <Field
                                    label={t('mkt.promo.budget')}
                                    hint={promotion.data ? t('mkt.promo.spent', { amount: taka(promotion.data.discount_spent) }) : t('mkt.promo.budgetHint')}
                                    error={errors.budget}
                                    optional
                                >
                                    {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} inputMode="decimal" className="tabular" prefix={TAKA} {...text('budget')} />}
                                </Field>
                                <Field label={t('mkt.coupon.starts')} hint={t('mkt.dhakaTime')} error={errors.starts_at} required={isFlash} optional={!isFlash}>
                                    {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} type="datetime-local" className="tabular" {...text('starts_at')} />}
                                </Field>
                                <Field label={t('mkt.coupon.ends')} hint={t('mkt.dhakaTime')} error={errors.ends_at} required={isFlash} optional={!isFlash}>
                                    {({ id: fid, describedBy, invalid }) => <Input id={fid} aria-describedby={describedBy} aria-invalid={invalid || undefined} type="datetime-local" className="tabular" {...text('ends_at')} />}
                                </Field>
                            </div>
                        </section>
                        <button type="submit" hidden />
                    </form>
                }
                aside={
                    <>
                        <SummaryCard ticket={(locale === 'bn' ? draft.name_bn || draft.name : draft.name) || '—'} ticketSub={t(`mkt.ptype.${draft.type}` as MessageKey)} facts={facts} note={t('mkt.serverNote')} />
                        {id !== null && report.data && <UsageReport summary={report.data.summary} recent={report.data.recent} />}
                    </>
                }
            />
            <ConfirmDialog
                open={confirm}
                title={t('mkt.deactivateTitle', { name: promotion.data?.name ?? '' })}
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

function TiersEditor({ tiers, onChange, errors, disabled }: { tiers: Tier[]; onChange: (t: Tier[]) => void; errors: Record<string, string>; disabled: boolean }) {
    const { t } = useI18n();
    const patch = (i: number, p: Partial<Tier>) => onChange(tiers.map((tr, j) => (j === i ? { ...tr, ...p } : tr)));
    return (
        <fieldset className="flex flex-col gap-3" disabled={disabled}>
            <legend className="mb-2 t-admin-body-strong text-fg-primary">{t('mkt.promo.tiers')}</legend>
            {errors['rules.tiers'] && <p className="t-admin-caption text-fg-primary">{errors['rules.tiers']}</p>}
            {tiers.map((tr, i) => (
                <div key={i} className="grid items-end gap-3 sm:grid-cols-[1fr_1fr_1fr_auto]">
                    <Field label={t('mkt.promo.tierMin')} error={errors[`rules.tiers.${i}.min_subtotal`]}>
                        {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} inputMode="decimal" className="tabular" prefix={TAKA} value={tr.min_subtotal} onChange={(e) => patch(i, { min_subtotal: e.target.value })} />}
                    </Field>
                    <Field label={t('mkt.promo.tierKind')}>
                        {({ id, describedBy }) => (
                            <Select id={id} aria-describedby={describedBy} value={tr.type} onChange={(e) => patch(i, { type: e.target.value === 'fixed' ? 'fixed' : 'percent' })}>
                                <option value="percent">{t('mkt.promo.kind.percent')}</option>
                                <option value="fixed">{t('mkt.promo.kind.fixedBundle')}</option>
                            </Select>
                        )}
                    </Field>
                    <Field label={t('mkt.promo.tierValue')} error={errors[`rules.tiers.${i}.value`]}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} inputMode="decimal" className="tabular" suffix={tr.type === 'percent' ? '%' : undefined} prefix={tr.type === 'fixed' ? TAKA : undefined} value={tr.value} onChange={(e) => patch(i, { value: e.target.value })} />
                        )}
                    </Field>
                    <Button type="button" variant="ghost" aria-label={t('mkt.promo.removeTier', { n: i + 1 })} onClick={() => onChange(tiers.filter((_, j) => j !== i))} disabled={tiers.length === 1}>
                        <TrashIcon size={16} aria-hidden />
                    </Button>
                </div>
            ))}
            <div>
                <Button type="button" variant="outline" size="sm" onClick={() => onChange([...tiers, { min_subtotal: '', type: 'percent', value: '' }])} disabled={tiers.length >= 10}>
                    <PlusIcon size={16} aria-hidden />
                    {t('mkt.promo.addTier')}
                </Button>
            </div>
        </fieldset>
    );
}

/** Flash-sale lines: variant search (server price + stock), sale price, quantity, per-order limit; claimed/remaining from the server. */
function FlashItemsEditor({ items, onChange, errors, disabled }: { items: FlashLine[]; onChange: (items: FlashLine[]) => void; errors: Record<string, string>; disabled: boolean }) {
    const { t, locale } = useI18n();
    const [q, setQ] = useState('');
    const results = useVariantSearch(q);
    const patch = (i: number, p: Partial<FlashLine>) => onChange(items.map((it, j) => (j === i ? { ...it, ...p } : it)));

    return (
        <section className={card} aria-labelledby="mkt-flash">
            <div className="flex flex-col gap-1">
                <h2 id="mkt-flash" className="t-admin-section text-fg-primary">
                    {t('mkt.flash.items')}
                </h2>
                <p className="t-admin-caption text-fg-secondary">{t('mkt.flash.itemsHint')}</p>
            </div>
            {errors.flash_items && <p className="t-admin-caption text-fg-primary">{errors.flash_items}</p>}
            {!disabled && (
                <div className="relative">
                    <Input aria-label={t('mkt.flash.addItem')} placeholder={t('mkt.flash.searchVariant')} value={q} onChange={(e) => setQ(e.target.value)} autoComplete="off" />
                    {q.trim().length >= 2 && results.data && (
                        <ul role="listbox" aria-label={t('mkt.flash.addItem')} className="absolute inset-x-0 top-full z-10 mt-1 max-h-72 overflow-auto rounded-sm border border-line-default bg-surface py-1 shadow-e2">
                            {results.data.length === 0 && <li className="px-3 py-2 t-admin-caption text-fg-secondary">{t('mkt.scope.noMatch')}</li>}
                            {results.data.map((v) => (
                                <li key={v.variant_id} role="option" aria-selected={items.some((i) => i.variant_id === v.variant_id)}>
                                    <button
                                        type="button"
                                        className="flex w-full items-center justify-between gap-3 px-3 py-2 text-start hover:bg-subtle"
                                        onClick={() => {
                                            if (!items.some((i) => i.variant_id === v.variant_id)) {
                                                const label = [locale === 'bn' ? (v.product_name_bn ?? v.product_name) : v.product_name, v.variant_name, v.sku].filter(Boolean).join(' · ');
                                                onChange([...items, { variant_id: v.variant_id, label, regular_price: v.price, sale_price: '', allotment: '', per_order_limit: '', claimed: 0, remaining: null }]);
                                            }
                                            setQ('');
                                        }}
                                    >
                                        <span className="min-w-0">
                                            <span className="block truncate t-admin-body text-fg-primary">{locale === 'bn' ? (v.product_name_bn ?? v.product_name) : v.product_name}</span>
                                            <span className="block t-admin-caption text-fg-tertiary">{[v.variant_name, v.sku, t('mkt.flash.stock', { count: v.available })].filter(Boolean).join(' · ')}</span>
                                        </span>
                                        <span className="t-admin-body tabular text-fg-primary">{taka(v.price)}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
            {items.length === 0 ? (
                <p className="t-admin-caption text-fg-secondary">{t('mkt.flash.noItems')}</p>
            ) : (
                <ul className="flex flex-col divide-y divide-line-default" aria-label={t('mkt.flash.items')}>
                    {items.map((it, i) => (
                        <li key={it.variant_id} className="grid items-end gap-3 py-3 sm:grid-cols-[minmax(0,2fr)_1fr_1fr_1fr_auto]">
                            <div className="flex min-w-0 flex-col gap-0.5 self-center">
                                <span className="truncate t-admin-body-strong text-fg-primary">{it.label}</span>
                                <span className="t-admin-caption tabular text-fg-tertiary">
                                    {t('mkt.flash.col.regular')}: {taka(it.regular_price)}
                                    {it.remaining !== null && ` · ${t('mkt.flash.claimed', { claimed: it.claimed, remaining: it.remaining })}`}
                                </span>
                            </div>
                            <Field label={t('mkt.flash.col.sale')} error={errors[`flash_items.${i}.sale_price`]}>
                                {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} inputMode="decimal" className="tabular" prefix={TAKA} value={it.sale_price} onChange={(e) => patch(i, { sale_price: e.target.value })} disabled={disabled} />}
                            </Field>
                            <Field label={t('mkt.flash.col.qty')} error={errors[`flash_items.${i}.allotment`]}>
                                {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} type="number" min={Math.max(1, it.claimed)} className="tabular" value={it.allotment} onChange={(e) => patch(i, { allotment: e.target.value })} disabled={disabled} />}
                            </Field>
                            <Field label={t('mkt.flash.col.limit')} error={errors[`flash_items.${i}.per_order_limit`]}>
                                {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} type="number" min={1} max={99} className="tabular" value={it.per_order_limit} onChange={(e) => patch(i, { per_order_limit: e.target.value })} disabled={disabled} />}
                            </Field>
                            <Button type="button" variant="ghost" aria-label={t('mkt.flash.remove', { name: it.label })} onClick={() => onChange(items.filter((_, j) => j !== i))} disabled={disabled || it.claimed > 0}>
                                <TrashIcon size={16} aria-hidden />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
