import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from 'react-router/dom';
import { router } from './app/router';
import '../../css/admin.css';

// Server data lives in TanStack Query and nowhere else. Zustand holds no server
// state — a second copy of server data in a global store is the most common way
// an admin dashboard goes silently stale. See ../../../CLAUDE.md rule 25a.
const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 30_000,
            retry: (failureCount, error) => {
                // Never retry an auth or authorisation failure — it will never succeed,
                // and retrying hides the real problem from the operator.
                const status = (error as { status?: number })?.status;
                if (status === 401 || status === 403 || status === 422) return false;
                return failureCount < 2;
            },
            refetchOnWindowFocus: false,
        },
    },
});

const el = document.getElementById('admin-root');
if (!el) throw new Error('#admin-root is missing from resources/views/admin.blade.php');

createRoot(el).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            <RouterProvider router={router} />
        </QueryClientProvider>
    </StrictMode>,
);
