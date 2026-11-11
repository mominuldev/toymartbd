import { useRouteError } from 'react-router';
import { QueryError } from '@/components/ui/QueryError';

/** Router error boundary: a render crash shows a recoverable message, never a blank page. */
export function RouteError() {
    const error = useRouteError();

    return (
        <div className="grid min-h-dvh place-items-center bg-canvas p-gutter-mobile">
            <QueryError error={error} onRetry={() => window.location.reload()} />
        </div>
    );
}
