import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { LogVerificationCallRequestInput, RejectHeldOrderRequestInput, SetPhoneStatusRequestInput, UpdateRiskRulesRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

/**
 * Fraud & risk (P6, checklist §2.4). Every number here is the server's: the browser never
 * scores, sums or blends anything (rules 1, 24). Our score and the courier record stay two
 * separate objects from the API to the screen.
 */

/** One factor of OUR score: Σ contribution = score (DATABASE-DESIGN §5.2). */
export type RiskFactorRow = {
    code: string;
    weight: number;
    value: string;
    contribution: number;
};
export type RiskLevel = 'low' | 'medium' | 'high';
export type ListStatusValue = 'normal' | 'watch' | 'blacklist' | 'whitelist';
export type OurScore = {
    stored: boolean;
    internal_score: number;
    level: string;
    breakdown: RiskFactorRow[];
    score_computed_at: string | null;
    counts: {
        total_orders: number;
        delivered: number;
        cancelled: number;
        returned: number;
        failed_delivery: number;
        refused: number;
    };
    last_order_at: string | null;
};
export type ListStatus = {
    value: ListStatusValue;
    reason: string | null;
    set_by: string | null;
    set_at: string | null;
};
/** CourierHistoryLookup's answer for one provider — verified external data, or "not connected". */
export type CourierRecord = {
    provider: string;
    name: string;
    name_bn: string | null;
    connected: boolean;
    reason: string | null;
    total_parcels: number | null;
    total_delivered: number | null;
    total_cancelled: number | null;
    total_fraud_reports: number | null;
    delivery_ratio: string | null;
    cancellation_ratio: string | null;
    fetched_at: string | null;
};
export type OrderRisk = {
    score: number | null;
    level: RiskLevel | null;
    verification_status: string;
    hold_reasons: string[];
    signals: Partial<RiskFactorRow>[];
    computed_at: string | null;
};
type QueueBase = Schema<'RiskQueueItemResource'>;
export type QueueRow = Omit<QueueBase, 'ours' | 'status' | 'order_risk'> & {
    ours: OurScore;
    status: ListStatus;
    order_risk: OrderRisk;
};
export type CallLogEntry = Schema<'RiskVerificationCallResource'>;
export type PhoneRisk = {
    phone: string;
    pii_masked: boolean;
    ours: OurScore;
    status: ListStatus;
    courier: { checked_at: string; providers: CourierRecord[] };
    recent_orders: {
        id: number;
        number: string;
        status: string;
        grand_total: string;
        placed_at: string | null;
    }[];
};
export type HeldOrderDetail = {
    order: QueueRow;
    call_log: CallLogEntry[];
    phone: PhoneRisk;
};
export type RiskRules = Schema<'RiskRulesResource'>;
export type CallOutcome = LogVerificationCallRequestInput['outcome'];
export type RulesInput = UpdateRiskRulesRequestInput;

export const factorCodes = ['cancellation_rate', 'courier_return_rate', 'failed_delivery', 'first_order', 'value_above_norm', 'address_quality', 'rapid_repeat'] as const;
export const callOutcomes: CallOutcome[] = ['confirmed', 'no_answer', 'switched_off', 'wrong_number', 'call_back', 'declined'];
export const holdReasons = ['high_risk_score', 'first_order_above_threshold', 'cod_advance_required', 'blacklisted', 'watchlist'] as const;

type Page<T> = {
    data: T[];
    meta: { next_cursor?: string | null; count?: number };
};

const keys = {
    all: ['risk'] as const,
    queue: (level: string) => ['risk', 'queue', level] as const,
    held: (id: number) => ['risk', 'held', id] as const,
    phone: (phone: string) => ['risk', 'phone', phone] as const,
    rules: ['risk', 'rules'] as const,
};

export function queuePath(level: string, cursor: string | null): string {
    const params = new URLSearchParams();
    if (level) params.set('filter[risk_level]', level);
    if (cursor) params.set('cursor', cursor);
    const text = params.toString();
    return `/risk/queue${text ? `?${text}` : ''}`;
}

export function useRiskQueue(level: string) {
    return useInfiniteQuery({
        queryKey: keys.queue(level),
        queryFn: ({ pageParam }) => api<Page<QueueRow>>(queuePath(level, pageParam)),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
    });
}

/** The side panel. Fetches the courier record on demand (the server never calls a courier inside a transaction, rule 4). */
export function useHeldOrder(id: number | null) {
    return useQuery({
        queryKey: keys.held(id ?? 0),
        queryFn: () => api<HeldOrderDetail>(`/risk/orders/${id}`),
        enabled: id !== null,
    });
}

export function usePhoneRisk(phone: string) {
    return useQuery({
        queryKey: keys.phone(phone),
        queryFn: () => api<PhoneRisk>(`/risk/lookup?phone=${encodeURIComponent(phone)}`),
        enabled: phone !== '',
        retry: false,
    });
}

export function useRiskRules() {
    return useQuery({
        queryKey: keys.rules,
        queryFn: () => api<RiskRules>('/risk/rules'),
    });
}

function useInvalidate() {
    const client = useQueryClient();
    return () => void client.invalidateQueries({ queryKey: keys.all });
}

export function useVerify() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: ({ id, note }: { id: number; note: string | null }) =>
            api<{ id: number; status: string }>(`/risk/orders/${id}/verify`, {
                method: 'POST',
                body: JSON.stringify({ note }),
            }),
        onSuccess: invalidate,
    });
}

export function useReject() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: ({ id, input }: { id: number; input: RejectHeldOrderRequestInput }) =>
            api<{ id: number; status: string }>(`/risk/orders/${id}/reject`, {
                method: 'POST',
                body: JSON.stringify(input),
            }),
        onSuccess: invalidate,
    });
}

export function useLogCall() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: ({ id, input }: { id: number; input: LogVerificationCallRequestInput }) =>
            api<CallLogEntry>(`/risk/orders/${id}/calls`, {
                method: 'POST',
                body: JSON.stringify(input),
            }),
        onSuccess: invalidate,
    });
}

export function useSetPhoneStatus() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: (input: SetPhoneStatusRequestInput) =>
            api<PhoneRisk>('/risk/phone-status', {
                method: 'PUT',
                body: JSON.stringify(input),
            }),
        onSuccess: invalidate,
    });
}

export function useRescore() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: (phone: string) =>
            api<PhoneRisk>('/risk/lookup/rescore', {
                method: 'POST',
                body: JSON.stringify({ phone }),
            }),
        onSuccess: invalidate,
    });
}

export function useSaveRules() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: (input: RulesInput) =>
            api<RiskRules>('/risk/rules', {
                method: 'PUT',
                body: JSON.stringify(input),
            }),
        onSuccess: invalidate,
    });
}
