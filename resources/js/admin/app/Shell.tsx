import { Outlet } from 'react-router';

/**
 * Interim shell, styled from @toymart/tokens (Theme B). The sidebar always renders
 * in Admin Dark, matching Figma page 07 section 14.
 *
 * The full AppShell (Figma Sidebar 187:473, Topbar 187:474, Topbar/Tablet 228:1857)
 * lands with the i18n layer in Phase 3. Strings stay out of this file until then:
 * a hardcoded English string in a component is a defect (rule 35).
 */
export function Shell() {
    return (
        <div className="flex min-h-dvh bg-canvas text-fg-primary">
            <aside
                data-theme="admin-dark"
                className="hidden w-64 shrink-0 border-r border-line-default bg-surface lg:block"
            />
            <div className="flex min-w-0 flex-1 flex-col">
                <header className="h-16 border-b border-line-default bg-surface" />
                <main className="flex-1 p-gutter-desktop">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}
