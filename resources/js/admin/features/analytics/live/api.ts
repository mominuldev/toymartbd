import { useQuery } from "@tanstack/react-query";
import type { Schema } from "@toymart/api-types";
import { api } from "@/lib/api";

export type LiveFeedKind = LiveFeedItem['kind'];

export type LiveFeedItem = LiveAnalytics['feed'][number];

/** `GET /api/v1/admin/analytics/live` (App\Http\Resources\Admin\Reports\LiveAnalyticsResource). */
export type LiveAnalytics = Schema<'LiveAnalytics'>;

/** Refreshes every minute while not paused; every window is the server's clock (rule 1). */
export const LIVE_REFRESH_MS = 60_000;

export function liveRefetchInterval(paused: boolean): number | false {
    return paused ? false : LIVE_REFRESH_MS;
}

export function useLiveAnalytics(paused: boolean) {
    return useQuery({
        queryKey: ["analytics", "live"],
        queryFn: () => api<LiveAnalytics>("/analytics/live"),
        refetchInterval: liveRefetchInterval(paused),
        refetchIntervalInBackground: false,
    });
}
