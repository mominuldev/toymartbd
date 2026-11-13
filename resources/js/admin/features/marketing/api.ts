import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { GenerateCouponsRequestInput, StoreCouponRequestInput, StorePromotionRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

/**
 * Marketing (P7, checklist §2.9 and §2.8 abandoned carts). Every discount, total and report
 * figure is the server's (rule 1): the browser echoes inputs and formats decimal strings.
 * Request bodies are the generated types (rule 27).
 */

export type Coupon = Schema<'Coupon'>;
export type Promotion = Schema<'Promotion'>;
export type CartRecovery = Schema<'CartRecovery'>;
export type CouponInput = StoreCouponRequestInput;
export type GenerateInput = GenerateCouponsRequestInput;
export type PromotionInput = StorePromotionRequestInput;
export type CouponType = Coupon['type'];
export type PromotionType = Promotion['type'];
export type PromotionStatus = Promotion['status'];
export type ScopeType = Coupon['applies_to'];
export type SkipReason = NonNullable<CartRecovery['skip_reason']>;
export type ComposerVariant = Schema<'OrderComposerVariant'>;
export type PickItem = { id: number; name: string; name_bn: string | null };

export type UsageSummary = { orders: number; discount_total: string; gross_sales: string; delivered_orders: number; delivered_revenue: string };
export type UsageRow = { order_id: number; order_number: string; status: string; phone: string; discount: string; grand_total: string; used_at: string };
export type CouponReport = { summary: UsageSummary; batch: UsageSummary | null; recent: UsageRow[] };
export type PromotionReport = { summary: UsageSummary; recent: UsageRow[] };
export type GenerateResult = { batch: string; count: number; codes: string[] };
export type RecoverySummary = {
    from: string;
    to: string;
    abandoned: number;
    messaged: number;
    skipped: Record<string, number>;
    sent: number;
    cost: string;
    restored: number;
    recovered: number;
    recovered_delivered: number;
    revenue_recovered: string;
    abandoned_value: string;
};

export const couponTypes: CouponType[] = ['percent', 'fixed', 'free_shipping'];
export const promotionTypes: PromotionType[] = ['discount', 'tiered', 'buy_x_get_y', 'bundle', 'free_delivery', 'flash_sale'];
export const promotionStatuses: PromotionStatus[] = ['running', 'scheduled', 'ended', 'inactive'];
export const scopeTypes: ScopeType[] = ['all', 'products', 'categories', 'brands'];
export const skipReasons: SkipReason[] = ['no_consent', 'opted_out', 'cooldown', 'already_ordered', 'below_minimum', 'no_phone', 'template_inactive', 'converted'];

type Page<T> = { data: T[]; meta: { next_cursor?: string | null } };

export const marketingKeys = {
    all: ['marketing'] as const,
    coupons: (q: string) => ['marketing', 'coupons', q] as const,
    coupon: (id: number) => ['marketing', 'coupon', id] as const,
    couponReport: (id: number) => ['marketing', 'coupon', id, 'report'] as const,
    promotions: (q: string) => ['marketing', 'promotions', q] as const,
    promotion: (id: number) => ['marketing', 'promotion', id] as const,
    promotionReport: (id: number) => ['marketing', 'promotion', id, 'report'] as const,
    recoveries: (status: string) => ['marketing', 'recoveries', status] as const,
    recoverySummary: (from: string, to: string) => ['marketing', 'recoveries', 'summary', from, to] as const,
    pick: (kind: string, q: string) => ['marketing', 'pick', kind, q] as const,
    variants: (q: string) => ['marketing', 'variants', q] as const,
};

function query(params: Record<string, string>, cursor: string | null): string {
    const search = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) if (value) search.set(key, value);
    if (cursor) search.set('cursor', cursor);
    const text = search.toString();
    return text ? `?${text}` : '';
}

export type CouponFilters = { search: string; status: string; batch: string };

export function couponsPath(f: CouponFilters, cursor: string | null): string {
    return `/coupons${query({ 'filter[search]': f.search, 'filter[status]': f.status, 'filter[batch]': f.batch }, cursor)}`;
}

export function useCoupons(f: CouponFilters) {
    return useInfiniteQuery({
        queryKey: marketingKeys.coupons(JSON.stringify(f)),
        queryFn: ({ pageParam }) => api<Page<Coupon>>(couponsPath(f, pageParam)),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        placeholderData: keepPreviousData,
    });
}

export function useCoupon(id: number | null) {
    return useQuery({
        queryKey: marketingKeys.coupon(id ?? 0),
        queryFn: () => api<Coupon>(`/coupons/${id}`),
        enabled: id !== null,
    });
}

export function useCouponReport(id: number | null) {
    return useQuery({
        queryKey: marketingKeys.couponReport(id ?? 0),
        queryFn: () => api<CouponReport>(`/coupons/${id}/report`),
        enabled: id !== null,
    });
}

function useInvalidate() {
    const client = useQueryClient();
    return () => void client.invalidateQueries({ queryKey: marketingKeys.all });
}

export function useSaveCoupon(id: number | null) {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: (input: CouponInput) =>
            api<Coupon>(id === null ? '/coupons' : `/coupons/${id}`, { method: id === null ? 'POST' : 'PUT', body: JSON.stringify(input) }),
        onSuccess: invalidate,
    });
}

export function useDeactivateCoupon() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: (id: number) => api<Coupon>(`/coupons/${id}`, { method: 'DELETE' }),
        onSuccess: invalidate,
    });
}

export function useGenerateCoupons() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: (input: GenerateInput) => api<GenerateResult>('/coupons/generate', { method: 'POST', body: JSON.stringify(input) }),
        onSuccess: invalidate,
    });
}

export type PromotionFilters = { search: string; status: string; type: string };

export function promotionsPath(f: PromotionFilters, cursor: string | null): string {
    return `/promotions${query({ 'filter[search]': f.search, 'filter[status]': f.status, 'filter[type]': f.type }, cursor)}`;
}

export function usePromotions(f: PromotionFilters) {
    return useInfiniteQuery({
        queryKey: marketingKeys.promotions(JSON.stringify(f)),
        queryFn: ({ pageParam }) => api<Page<Promotion>>(promotionsPath(f, pageParam)),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        placeholderData: keepPreviousData,
    });
}

export function usePromotion(id: number | null) {
    return useQuery({
        queryKey: marketingKeys.promotion(id ?? 0),
        queryFn: () => api<Promotion>(`/promotions/${id}`),
        enabled: id !== null,
    });
}

export function usePromotionReport(id: number | null) {
    return useQuery({
        queryKey: marketingKeys.promotionReport(id ?? 0),
        queryFn: () => api<PromotionReport>(`/promotions/${id}/report`),
        enabled: id !== null,
    });
}

export function useSavePromotion(id: number | null) {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: (input: PromotionInput) =>
            api<Promotion>(id === null ? '/promotions' : `/promotions/${id}`, { method: id === null ? 'POST' : 'PUT', body: JSON.stringify(input) }),
        onSuccess: invalidate,
    });
}

export function useDeactivatePromotion() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: (id: number) => api<Promotion>(`/promotions/${id}`, { method: 'DELETE' }),
        onSuccess: invalidate,
    });
}

export function useRecoveries(status: string) {
    return useInfiniteQuery({
        queryKey: marketingKeys.recoveries(status),
        queryFn: ({ pageParam }) => api<Page<CartRecovery>>(`/cart-recoveries${query({ 'filter[status]': status }, pageParam)}`),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
    });
}

export function useRecoverySummary(from: string, to: string) {
    return useQuery({
        queryKey: marketingKeys.recoverySummary(from, to),
        queryFn: () => api<RecoverySummary>(`/cart-recoveries/summary${query({ from, to }, null)}`),
    });
}

export type PickKind = 'products' | 'categories' | 'brands';

/** Search products, categories or brands for a scope picker (≥ 2 characters). */
export function usePick(kind: PickKind, q: string) {
    return useQuery({
        queryKey: marketingKeys.pick(kind, q),
        queryFn: () => api<{ data: PickItem[] }>(`/${kind}?filter[search]=${encodeURIComponent(q)}`).then((r) => r.data),
        enabled: q.trim().length >= 2,
        placeholderData: keepPreviousData,
    });
}

/** Variant search for flash-sale lines — the order composer's lookup (price + stock, server-side). */
export function useVariantSearch(q: string) {
    return useQuery({
        queryKey: marketingKeys.variants(q),
        queryFn: () => api<{ data: ComposerVariant[] }>(`/orders/compose/variants?q=${encodeURIComponent(q)}`).then((r) => r.data),
        enabled: q.trim().length >= 2,
        placeholderData: keepPreviousData,
    });
}
