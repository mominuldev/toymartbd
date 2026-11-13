import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { MapCourierLocationRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

/*
 * Courier location mapping (P6, DATABASE-DESIGN §3.6). The server mirrors the courier's own
 * zones; staff map them to our districts/upazilas by hand (rule 32). Suggestions are only
 * suggestions — nothing here maps without an explicit confirm, and nothing is computed in
 * the browser: every list and count comes from the server.
 */

export type CourierLocation = Schema<'CourierLocation'>;
export type LocationOverview = Schema<'CourierLocationOverview'>;
export type CourierPlace = Schema<'CourierPlace'>;
export type ZoneSuggestion = Schema<'CourierZoneSuggestion'>;
export type PlaceSuggestion = Schema<'CourierPlaceSuggestion'>;
export type MapInput = MapCourierLocationRequestInput;

export type LocationStatusFilter = '' | 'unmapped' | 'mapped' | 'inactive';
export type LocationFilters = { city: string; status: LocationStatusFilter; search: string };

type Page<T> = { data: T[]; meta: { next_cursor?: string | null } };
type List<T> = { data: T[] };

const base = (provider: string) => `/courier-locations/${encodeURIComponent(provider)}`;

const keys = {
    all: (provider: string) => ['courier-locations', provider] as const,
    overview: (provider: string) => ['courier-locations', provider, 'overview'] as const,
    needs: (provider: string) => ['courier-locations', provider, 'needs-mapping'] as const,
    list: (provider: string, filters: LocationFilters) => ['courier-locations', provider, 'list', filters] as const,
    places: (provider: string, q: string) => ['courier-locations', provider, 'places', q] as const,
    zoneSuggestions: (provider: string, district: number, upazila: number | null) => ['courier-locations', provider, 'suggest-zones', district, upazila] as const,
    placeSuggestions: (provider: string, location: number) => ['courier-locations', provider, 'suggest-places', location] as const,
};

function query(entries: Record<string, string | number | null | undefined>): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(entries)) if (value !== null && value !== undefined && value !== '') params.set(key, String(value));
    const text = params.toString();
    return text ? `?${text}` : '';
}

export function useLocationOverview(provider: string) {
    return useQuery({ queryKey: keys.overview(provider), queryFn: () => api<LocationOverview>(base(provider)) });
}

/** Our places that waiting orders need and that have nothing mapped — most orders first. */
export function useNeedsMapping(provider: string) {
    return useQuery({ queryKey: keys.needs(provider), queryFn: () => api<List<CourierPlace>>(`${base(provider)}/needs-mapping`) });
}

export function useCourierLocations(provider: string, filters: LocationFilters, enabled = true) {
    return useInfiniteQuery({
        queryKey: keys.list(provider, filters),
        queryFn: ({ pageParam }) =>
            api<Page<CourierLocation>>(
                `${base(provider)}/locations${query({ 'filter[city]': filters.city, 'filter[status]': filters.status, 'filter[search]': filters.search, cursor: pageParam })}`,
            ),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        placeholderData: keepPreviousData,
        enabled,
    });
}

/** The place picker: our districts and upazilas matching the text (English or Bangla). */
export function usePlaceSearch(provider: string, q: string) {
    return useQuery({
        queryKey: keys.places(provider, q),
        queryFn: () => api<List<CourierPlace>>(`${base(provider)}/places${query({ q })}`),
        enabled: q.length >= 2,
        placeholderData: keepPreviousData,
    });
}

/** The zone picker: active, unmapped courier rows whose zone or area name contains the text. */
export function useZoneSearch(provider: string, search: string) {
    return useQuery({
        queryKey: keys.list(provider, { city: '', status: 'unmapped', search }),
        queryFn: () => api<Page<CourierLocation>>(`${base(provider)}/locations${query({ 'filter[status]': 'unmapped', 'filter[search]': search })}`),
        enabled: search.length >= 2,
        placeholderData: keepPreviousData,
    });
}

export function useZoneSuggestions(provider: string, place: CourierPlace | null) {
    return useQuery({
        queryKey: keys.zoneSuggestions(provider, place?.district_id ?? 0, place?.upazila_id ?? null),
        queryFn: () => api<List<ZoneSuggestion>>(`${base(provider)}/suggestions${query({ district_id: place?.district_id, upazila_id: place?.upazila_id })}`),
        enabled: place !== null,
    });
}

export function usePlaceSuggestions(provider: string, location: CourierLocation | null) {
    return useQuery({
        queryKey: keys.placeSuggestions(provider, location?.id ?? 0),
        queryFn: () => api<List<PlaceSuggestion>>(`${base(provider)}/locations/${location?.id ?? 0}/suggestions`),
        enabled: location !== null,
    });
}

function useInvalidate(provider: string) {
    const client = useQueryClient();
    return () => client.invalidateQueries({ queryKey: keys.all(provider) });
}

export function useMapLocation(provider: string) {
    const invalidate = useInvalidate(provider);
    return useMutation({
        mutationFn: ({ locationId, input }: { locationId: number; input: MapInput }) =>
            api<CourierLocation>(`${base(provider)}/locations/${locationId}/mapping`, { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: invalidate,
    });
}

export function useUnmapLocation(provider: string) {
    const invalidate = useInvalidate(provider);
    return useMutation({
        mutationFn: (locationId: number) => api<CourierLocation>(`${base(provider)}/locations/${locationId}/mapping`, { method: 'DELETE' }),
        onSuccess: invalidate,
    });
}

/** Queue a fresh pull of the courier's tree; it runs in the background (202). */
export function useSyncLocations(provider: string) {
    const invalidate = useInvalidate(provider);
    return useMutation({
        mutationFn: () => api<void>(`${base(provider)}/sync`, { method: 'POST' }),
        onSuccess: invalidate,
    });
}
