import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { Sidebar } from '@/components/shell/Sidebar';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { usePosContext } from './api';
import { OpenDrawer } from './OpenDrawer';
import { Register } from './Register';

/**
 * POS register — Figma B · 33 (241:48158), Theme B. Full height beside the 64-wide dark
 * sidebar rail, no topbar (like the homepage builder): the drawer header, then the product
 * grid and the cart / payment panel.
 *
 * Every figure — line totals, discounts, total, change, what is still due — is the server's
 * quote (rule 1); the register only collects lines, the phone, the coupon and the tenders.
 * Without an open drawer the page asks to open one at a branch with a float.
 */
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

    return (
        <div className="flex h-dvh bg-canvas text-fg-primary">
            <aside data-theme="admin-dark" className="sticky top-0 hidden h-dvh w-16 shrink-0 lg:block print:hidden">
                <Sidebar permissions={me?.permissions ?? []} collapsed user={me ? { name: me.name, role: '' } : undefined} />
            </aside>
            <main id="content" className="flex min-w-0 flex-1 flex-col">
                {body}
            </main>
        </div>
    );
}
