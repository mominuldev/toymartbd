import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

type Ping = { ok: boolean; audience: string };

/**
 * Stack-proving screen, not the real dashboard. It exists so that Laravel + Vite +
 * React + TanStack Query + the Sanctum cookie session are verified end to end
 * before any feature is built on top of them. The real dashboard layout is in
 * docs/_design/04-admin-spec.md Part B.
 */
export function Dashboard() {
    const { data, isPending, error } = useQuery({
        queryKey: ['ping'],
        queryFn: () => api<Ping>('/ping'),
    });

    return (
        <section className="space-y-4">
            <h1 className="text-xl font-bold tracking-tight">Dashboard</h1>
            <p className="max-w-prose text-sm text-neutral-600 dark:text-neutral-400">
                Scaffold only. Screens are built from the specifications in{' '}
                <code className="font-mono text-xs">docs/_design/</code> once the Figma
                library is published.
            </p>
            <div className="rounded-md border border-neutral-200 p-4 text-sm dark:border-neutral-800">
                <p className="font-medium">API connectivity</p>
                {isPending && <p className="text-neutral-500">Checking…</p>}
                {error && <p className="text-red-600">{(error as Error).message}</p>}
                {data && (
                    <p className="font-mono text-xs text-green-700 dark:text-green-400">
                        /api/v1/admin/ping → audience: {data.audience}
                    </p>
                )}
            </div>
        </section>
    );
}
