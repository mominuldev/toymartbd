import { useCallback, useSyncExternalStore } from 'react';
import { useMe, type Me } from '@/features/auth/api';

/** One active stock location, as `GET /auth/me` → `branches.locations` gives it. */
export type WorkingBranchLocation = Me['branches']['locations'][number];

const KEY = 'tm-admin-working-branch';
const listeners = new Set<() => void>();

function read(): number | null {
    try {
        const raw = window.localStorage.getItem(KEY);
        return raw !== null && /^\d+$/.test(raw) ? Number(raw) : null;
    } catch {
        return null;
    }
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);
    // Another tab chose a branch: follow it.
    const onStorage = (event: StorageEvent) => {
        if (event.key === KEY) listener();
    };
    window.addEventListener('storage', onStorage);
    return () => {
        listeners.delete(listener);
        window.removeEventListener('storage', onStorage);
    };
}

/** Saves the choice for this device; storage may be unavailable (private window), the choice then lasts the session. */
function write(id: number): void {
    try {
        window.localStorage.setItem(KEY, String(id));
        memory = null;
    } catch {
        // No storage: the choice lasts this page's life.
        memory = id;
    }
    listeners.forEach((listener) => listener());
}

let memory: number | null = null;

function snapshot(): number | null {
    return read() ?? memory;
}

/**
 * The working branch (owner decision 2026-11-16): a per-device preference picked in the
 * sidebar's branch switcher. It is only the DEFAULT branch for the POS drawer, purchases,
 * receipts, adjustments, transfers and the inventory screen — never a filter that hides
 * another branch's data. A stored id that is no longer an active location is ignored and the
 * default location is used instead. UI state, not server data (rule 25a): the locations come
 * from `/auth/me`, the choice from localStorage.
 */
export function useWorkingBranch(): {
    multi: boolean;
    locations: WorkingBranchLocation[];
    current: WorkingBranchLocation | null;
    select: (id: number) => void;
} {
    const { data: me } = useMe();
    const stored = useSyncExternalStore(subscribe, snapshot, () => null);
    const locations = me?.branches.locations ?? [];
    const current = locations.find((l) => l.id === stored) ?? locations.find((l) => l.is_default) ?? locations[0] ?? null;
    const select = useCallback((id: number) => write(id), []);

    return { multi: me?.branches.multi_branch ?? false, locations, current, select };
}

/**
 * The working branch's id when it is one of `ids` (a screen's own list of selectable
 * locations), else null — for a form's initial value.
 */
export function useWorkingBranchIn(ids: readonly number[]): number | null {
    const { current } = useWorkingBranch();
    return current !== null && ids.includes(current.id) ? current.id : null;
}
