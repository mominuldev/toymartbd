import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from 'react-router/dom';
import { router } from './app/router';
import { I18nProvider } from './i18n/I18nProvider';
import { createQueryClient } from './lib/queryClient';
import '../../css/admin.css';

const queryClient = createQueryClient();

const el = document.getElementById('admin-root');
if (!el) throw new Error('#admin-root is missing from resources/views/admin.blade.php');

createRoot(el).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            <I18nProvider>
                <RouterProvider router={router} />
            </I18nProvider>
        </QueryClientProvider>
    </StrictMode>,
);
