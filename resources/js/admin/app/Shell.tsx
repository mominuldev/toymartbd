import { Outlet } from 'react-router';

/**
 * Placeholder shell. The real AppShell — collapsible sidebar, topbar with global
 * search, notifications and theme toggle — is specified in
 * docs/_design/04-admin-spec.md and built once the Figma library is published.
 */
export function Shell() {
    return (
        <div className="min-h-dvh bg-white text-neutral-900 dark:bg-neutral-900 dark:text-neutral-50">
            <header className="border-b border-neutral-200 px-6 py-4 dark:border-neutral-800">
                <p className="text-sm font-semibold tracking-tight">Toy Mart BD — Admin</p>
            </header>
            <main className="p-6">
                <Outlet />
            </main>
        </div>
    );
}
