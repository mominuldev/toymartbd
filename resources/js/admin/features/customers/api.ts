import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { StoreCustomerNoteRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

export type CustomerRow = Schema<'CustomerListItem'>;
export type CustomerProfile = Schema<'CustomerProfile'>;
export type CustomerNote = Schema<'CustomerNote'>;
export type NotificationPreference = Schema<'NotificationPreference'>;
export type Segment = CustomerRow['segment'];
export type RiskStatus = NonNullable<CustomerRow['risk']>['status'];
export type RiskLevel = NonNullable<CustomerRow['risk']>['level'];
/** The POST body — the generated request type, never a hand-written copy (rule 27). */
export type NoteInput = StoreCustomerNoteRequestInput;

export const segments: Segment[] = ['new', 'active', 'vip', 'at_risk', 'lapsed', 'no_orders'];
export const riskStatuses: RiskStatus[] = ['normal', 'watch', 'blacklist', 'whitelist'];
export const sorts = ['-last_order', '-lifetime_value', '-orders_count', '-created'] as const;
export type Sort = (typeof sorts)[number];
export const lastOrderRanges = ['30', '90', '180', 'older'] as const;
export type LastOrderRange = (typeof lastOrderRanges)[number];

/** `meta` of GET /customers: page info plus the segment tiles and the thresholds in force. */
export type CustomerListMeta = {
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    counts: Partial<Record<Segment | 'all' | 'blacklisted', number>>;
    thresholds: { vip_min_lifetime_value: string; at_risk_after_days: number; lapsed_after_days: number };
};
export type CustomerList = { data: CustomerRow[]; meta: CustomerListMeta };

/** Every filter is a server allowlist (rule 16); the browser computes nothing (rule 1). */
export type CustomerFilters = {
    search: string;
    segment: Segment | '';
    account: '1' | '0' | '';
    risk: RiskStatus | '';
    district: string;
    /** Dhaka dates; the API takes them as inclusive bounds on the last order. */
    lastOrderFrom: string;
    lastOrderTo: string;
    sort: Sort;
    page: number;
};

export const customerKeys = {
    all: ['customers'] as const,
    list: (f: CustomerFilters) => ['customers', 'list', f] as const,
    detail: (id: number) => ['customers', 'detail', id] as const,
    notes: (id: number) => ['customers', 'notes', id] as const,
    preferences: (id: number) => ['customers', 'preferences', id] as const,
};

export function customersPath(f: CustomerFilters): string {
    const params = new URLSearchParams();
    const entries: Record<string, string> = {
        'filter[search]': f.search,
        'filter[segment]': f.segment,
        'filter[has_account]': f.account,
        'filter[risk]': f.risk,
        'filter[district_id]': f.district,
        'filter[last_order_from]': f.lastOrderFrom,
        'filter[last_order_to]': f.lastOrderTo,
    };
    for (const [key, value] of Object.entries(entries)) if (value) params.set(key, value);
    if (f.sort !== '-last_order') params.set('sort', f.sort);
    if (f.page > 1) params.set('page', String(f.page));
    const text = params.toString();
    return `/customers${text ? `?${text}` : ''}`;
}

export function useCustomers(filters: CustomerFilters) {
    return useQuery({
        queryKey: customerKeys.list(filters),
        queryFn: () => api<CustomerList>(customersPath(filters)),
        placeholderData: keepPreviousData,
    });
}

export function useCustomer(id: number) {
    return useQuery({
        queryKey: customerKeys.detail(id),
        queryFn: () => api<CustomerProfile>(`/customers/${id}`),
        enabled: Number.isInteger(id) && id > 0,
    });
}

export function useCustomerNotes(id: number, enabled = true) {
    return useQuery({
        queryKey: customerKeys.notes(id),
        queryFn: () => api<{ data: CustomerNote[] }>(`/customers/${id}/notes`).then((r) => r.data),
        enabled: enabled && id > 0,
    });
}

export function useAddCustomerNote(id: number) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: NoteInput) => api<CustomerNote>(`/customers/${id}/notes`, { method: 'POST', body: JSON.stringify(input) }),
        onSuccess: () => {
            void client.invalidateQueries({ queryKey: customerKeys.notes(id) });
            void client.invalidateQueries({ queryKey: customerKeys.detail(id) });
        },
    });
}

/** The existing notification-preferences endpoint (P4-NOT-03), read here for the profile card. */
export function useNotificationPreferences(id: number, enabled: boolean) {
    return useQuery({
        queryKey: customerKeys.preferences(id),
        queryFn: () => api<{ data: NotificationPreference[] }>(`/notifications/customers/${id}/preferences`).then((r) => r.data),
        enabled: enabled && id > 0,
    });
}

export type District = Schema<'StorefrontDistrict'>;

/** The 64 districts for the filter — the order composer's lookup (needs orders.create). */
export function useDistricts(enabled: boolean) {
    return useQuery({
        queryKey: ['customers', 'districts'],
        queryFn: () => api<{ data: District[] }>('/orders/compose/districts').then((r) => r.data),
        enabled,
        staleTime: Infinity,
    });
}
