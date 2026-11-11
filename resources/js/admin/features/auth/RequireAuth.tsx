import { Navigate, Outlet, useLocation } from 'react-router';
import { useMe } from './api';
import { QueryError } from '@/components/ui/QueryError';

/** Gate for every signed-in route. No session → /login, remembering where the user was going. */
export function RequireAuth() {
    const me = useMe();
    const location = useLocation();

    if (me.isPending) {
        return <div className="min-h-dvh bg-canvas" aria-busy="true" />;
    }

    if (me.isError) {
        return (
            <div className="grid min-h-dvh place-items-center bg-canvas p-gutter-mobile">
                <QueryError error={me.error} onRetry={() => void me.refetch()} />
            </div>
        );
    }

    if (me.data === null) {
        const from = location.pathname + location.search;
        return <Navigate to="/login" replace state={{ from }} />;
    }

    return <Outlet />;
}
