import { createBrowserRouter } from 'react-router';
import { routes } from './routes';

// Laravel serves this SPA from a catch-all at /admin/*, so the router basename
// must match that prefix or deep links 404 on refresh.
export const router = createBrowserRouter(routes, { basename: '/admin' });
