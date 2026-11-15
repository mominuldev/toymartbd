import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { Sidebar } from '@/components/shell/Sidebar';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { usePosContext } from './api';
import { OpenDrawer } from './OpenDrawer';
import { Register } from './Register';

/**
 * POS — Figma B · 33 POS v2 (344:63829), Theme B. An open drawer gets the whole screen: the
 * dark POS bar (with a way back to the dashboard) replaces the sidebar, so the cashier sees
 * only the catalog and the current sale. Opening a drawer, loading and errors keep the
 * 64-wide sidebar rail.
 *
 * Every figure — line totals, discounts, total, change, what is still due — is the server's
 * quote (rule 1); the register only collects lines, the phone, the coupon and the tenders.
 * Without an open drawer the page asks to open one at a branch with a float.
 */
/**
 * Below `lg` the register runs on a counter tablet or a phone: every control, in the page and in
 * its dialogs, is at least 44 × 44 (fields 44 tall) — a finger, not a mouse. The desktop till
 * keeps the Figma sizes.
 */
const TOUCH = 'max-lg:[&_button]:min-h-11 max-lg:[&_button]:min-w-11 max-lg:[&_select]:min-h-11 max-lg:[&_input:not([type=checkbox],[type=radio])]:min-h-11 max-lg:[&_div:has(>input)]:min-h-11';

export function PosPage() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const allowed = can(me, 'pos.use');
    const context = usePosContext();

    let body;
    if (me && !allowed) {
        body = (
            <div className="grid flex-1 place-items-center p-6">
                <EmptyState reason="noPermission" title={t('pos.noPermission')} titleAs="h1" />
            </div>
        );
    } else if (context.isError) {
        body = (
            <div className="p-6">
                <QueryError error={context.error} onRetry={() => void context.refetch()} />
            </div>
        );
    } else if (!context.data) {
        body = <p className="p-6 t-admin-body text-fg-secondary">{t('pos.loading')}</p>;
    } else if (context.data.session) {
        body = <Register key={context.data.session.id} context={context.data} session={context.data.session} />;
    } else {
        body = <OpenDrawer context={context.data} />;
    }

    if (context.data?.session && allowed) {
        return (
            <main id="content" className={`flex h-dvh flex-col bg-canvas text-fg-primary ${TOUCH}`}>
                {body}
            </main>
        );
    }

    return (
        <div className="flex h-dvh bg-canvas text-fg-primary">
            <aside data-theme="admin-dark" className="sticky top-0 hidden h-dvh w-16 shrink-0 lg:block print:hidden">
                <Sidebar permissions={me?.permissions ?? []} collapsed user={me ? { name: me.name, role: '' } : undefined} />
            </aside>
            <main id="content" className={`flex min-w-0 flex-1 flex-col ${TOUCH}`}>
                {body}
            </main>
        </div>
    );
}
