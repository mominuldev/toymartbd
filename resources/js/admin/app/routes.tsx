import type { RouteObject } from 'react-router';
import { Shell } from './Shell';
import { Dashboard } from './Dashboard';
import { NotFound } from './NotFound';
import { ComingSoon } from './ComingSoon';
import { RouteError } from './RouteError';
import { RequireAuth } from '@/features/auth/RequireAuth';
import { LoginPage } from '@/features/auth/LoginPage';
import { sectionPaths } from '@/components/shell/nav';

export const routes: RouteObject[] = [
    { path: '/login', element: <LoginPage />, errorElement: <RouteError /> },
    {
        element: <RequireAuth />,
        errorElement: <RouteError />,
        children: [
            {
                path: '/',
                element: <Shell />,
                children: [
                    { index: true, element: <Dashboard /> },
                    ...sectionPaths.map((item) => ({ path: `${item.path.slice(1)}/*`, element: <ComingSoon title={item.key} /> })),
                    { path: '*', element: <NotFound /> },
                ],
            },
        ],
    },
];
