import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from 'react-router/dom';
import { router } from './app/router';
import { I18nProvider } from './i18n/I18nProvider';
import { createQueryClient } from './lib/queryClient';
import { ToastProvider } from './components/ui/Toast';
import { initSentry, reportReactError } from './lib/sentry';
import '../../css/admin.css';

// Error reporting (P10): a no-op unless the server rendered a Sentry DSN for the admin.
void initSentry();

const queryClient = createQueryClient();

const el = document.getElementById('admin-root');
if (!el) throw new Error('#admin-root is missing from resources/views/admin.blade.php');

createRoot(el, { onUncaughtError: reportReactError, onCaughtError: reportReactError }).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            <I18nProvider>
                <ToastProvider>
                    <RouterProvider router={router} />
                </ToastProvider>
            </I18nProvider>
        </QueryClientProvider>
    </StrictMode>,
);
