import { Navigate, Outlet, useLocation } from 'react-router';
import { useMe, type Me } from './api';
import { QueryError } from '@/components/ui/QueryError';

/** Where someone who must set up two-factor is held (owner decision 2026-11-14). */
export const TWO_FACTOR_SETUP_PATH = '/account/two-factor';

/**
 * Gate for every signed-in route. No session → /login, remembering where the user was going.
 * Someone whose role can see money and who has not set up two-factor is held on the setup
 * page — the API refuses everything else with 403 `two-factor-required` meanwhile.
 */
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

    // Optional chaining: a cached `/me` from before the field existed (or an old fixture) has none.
    const twoFactor = me.data.two_factor as Me['two_factor'] | undefined;
    if (twoFactor?.required && !twoFactor.enabled && location.pathname !== TWO_FACTOR_SETUP_PATH) {
        return <Navigate to={TWO_FACTOR_SETUP_PATH} replace />;
    }

    return <Outlet />;
}
