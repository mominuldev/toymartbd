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
            <h1 className="t-admin-page-title">Dashboard</h1>
            <p className="t-admin-body max-w-prose text-fg-secondary">
                Scaffold only. Screens are built from the specifications in{' '}
                <code className="t-admin-mono-sm">docs/_design/</code> and Figma
                page 07 (Theme B).
            </p>
            <div className="t-admin-body rounded-md border border-line-default bg-surface p-4 shadow-e1">
                <p className="t-admin-body-strong">API connectivity</p>
                {isPending && <p className="text-fg-tertiary">Checking…</p>}
                {error && <p className="text-feedback-danger">{(error as Error).message}</p>}
                {data && (
                    <p className="t-admin-mono-sm text-feedback-success">
                        /api/v1/admin/ping → audience: {data.audience}
                    </p>
                )}
            </div>
        </section>
    );
}
