import { keepPreviousData, useQuery } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { api } from '@/lib/api';

export type BoardRow = Schema<'ShipmentBoardRow'>;
export type BoardStats = Schema<'ShipmentBoardStats'>;
export type BoardIssues = Schema<'ShipmentBoardIssues'>;
export type ShipmentTracking = Schema<'ShipmentTracking'>;
export type BoardStatus = BoardRow['status'];
export type BoardEvent = ShipmentTracking['events'][number];
export type BoardProvider = BoardRow['provider'];

/**
 * The board's tabs, in Figma B · 08 order. They map onto `shipments.status` on the server
 * (ShipmentsBoard::tab): in transit = booked … out for delivery + on hold; delivered includes
 * partly delivered; failed attempts = on hold or a re-attempt, so it overlaps the others.
 */
export const boardTabs = ['all', 'submitted', 'in_transit', 'delivered', 'returned', 'failed_attempts'] as const;
export type BoardTab = (typeof boardTabs)[number];

export type BoardFilters = {
    tab: BoardTab;
    provider: string;
    from: string;
    to: string;
    search: string;
    cursor: string | null;
};

type Page = {
    data: BoardRow[];
    meta: {
        next_cursor?: string | null;
        prev_cursor?: string | null;
        counts: Record<BoardTab, number>;
        from: string;
        to: string;
        providers: BoardProvider[];
    };
};

export const boardKeys = {
    all: ['shipments-board'] as const,
    list: (f: BoardFilters) => ['shipments-board', 'list', f] as const,
    stats: (from: string, to: string, provider: string) => ['shipments-board', 'stats', from, to, provider] as const,
    issues: ['shipments-board', 'issues'] as const,
    tracking: (id: number) => ['shipments-board', 'tracking', id] as const,
};

/** Every filter is a server-side allowlisted `filter[…]` (rule 16); the server answers 400 to anything else. */
export function boardPath(f: BoardFilters): string {
    const params = new URLSearchParams();
    if (f.tab !== 'all') params.set('filter[tab]', f.tab);
    if (f.provider) params.set('filter[provider]', f.provider);
    if (f.from) params.set('filter[from]', f.from);
    if (f.to) params.set('filter[to]', f.to);
    if (f.search) params.set('filter[search]', f.search);
    if (f.cursor) params.set('cursor', f.cursor);
    const qs = params.toString();
    return `/shipments/board${qs ? `?${qs}` : ''}`;
}

export function statsPath(from: string, to: string, provider: string): string {
    const params = new URLSearchParams();
    if (from) params.set('filter[from]', from);
    if (to) params.set('filter[to]', to);
    if (provider) params.set('filter[provider]', provider);
    const qs = params.toString();
    return `/shipments/board/stats${qs ? `?${qs}` : ''}`;
}

export function useBoard(f: BoardFilters) {
    return useQuery({
        queryKey: boardKeys.list(f),
        queryFn: () => api<Page>(boardPath(f)),
        placeholderData: keepPreviousData,
    });
}

export function useBoardStats(from: string, to: string, provider: string) {
    return useQuery({
        queryKey: boardKeys.stats(from, to, provider),
        queryFn: () => api<BoardStats>(statsPath(from, to, provider)),
        placeholderData: keepPreviousData,
    });
}

export function useBoardIssues() {
    return useQuery({
        queryKey: boardKeys.issues,
        queryFn: () => api<BoardIssues>('/shipments/board/issues'),
    });
}

export function useShipmentTracking(id: number | null) {
    return useQuery({
        queryKey: boardKeys.tracking(id ?? 0),
        queryFn: () => api<ShipmentTracking>(`/shipments/${id}/tracking`),
        enabled: id !== null,
    });
}

/** The existing label route (P6): shipments.view + customers.view_pii, only once a consignment id exists. */
export function labelUrl(shipmentId: number, locale: string): string {
    return `/api/v1/admin/shipments/${shipmentId}/label?locale=${locale === 'bn' ? 'bn' : 'en'}`;
}
