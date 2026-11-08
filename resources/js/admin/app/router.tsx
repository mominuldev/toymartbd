import { createBrowserRouter } from 'react-router';
import { Shell } from './Shell';
import { Dashboard } from './Dashboard';
import { NotFound } from './NotFound';

// Laravel serves this SPA from a catch-all at /admin/*, so the router basename
// must match that prefix or deep links 404 on refresh.
export const router = createBrowserRouter(
    [
        {
            path: '/',
            element: <Shell />,
            children: [
                { index: true, element: <Dashboard /> },
                { path: '*', element: <NotFound /> },
            ],
        },
    ],
    { basename: '/admin' },
);
