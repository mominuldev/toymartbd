import { useSyncExternalStore } from 'react';
import { Link } from 'react-router';
import { CashRegisterIcon, CheckCircleIcon, ClockIcon, CloudSlashIcon, SquaresFourIcon, StackIcon, StorefrontIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { PosSession } from './api';
import { dhakaTime, takaText } from './format';
import { BrandLogo } from '@/components/brand/BrandLogo';

function subscribeOnline(callback: () => void): () => void {
    window.addEventListener('online', callback);
    window.addEventListener('offline', callback);
    return () => {
        window.removeEventListener('online', callback);
        window.removeEventListener('offline', callback);
    };
}

const pill = 'inline-flex h-11 min-w-11 justify-center shrink-0 lg:h-7 lg:min-w-0 items-center gap-1.5 rounded-full px-2.5 t-admin-caption-strong';

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
}

/**
 * The POS top bar (Figma B · 33 POS v2 — 344:63829), always dark (data-theme="admin-dark"):
 * logo, branch · register as the page heading, a way back to the dashboard, then the
 * connection, the drawer (opened at · float), held carts (F8), Close drawer and the cashier.
 */
export function PosBar({ session, heldCount, onHeld, onCloseDrawer }: { session: PosSession; heldCount: number; onHeld: () => void; onCloseDrawer: () => void }) {
    const { t, locale } = useI18n();
    const online = useSyncExternalStore(
        subscribeOnline,
        () => navigator.onLine,
        () => true,
    );
    const branch = locale === 'bn' && session.location.name_bn ? session.location.name_bn : session.location.name;

    return (
        <header data-theme="admin-dark" className="flex h-14 shrink-0 items-center gap-2 bg-canvas px-3 text-fg-primary sm:gap-3 sm:px-5 print:hidden">
            {/* The On dark lockup (the bar is admin-dark): the logo is the store name. */}
            <BrandLogo alt={t('app.name')} className="h-9" />
            <span aria-hidden className="h-6 w-px shrink-0 bg-line-default" />
            <h1 className="flex min-w-0 items-center gap-1.5 t-admin-body text-fg-primary">
                <StorefrontIcon size={16} aria-hidden className="shrink-0 text-fg-secondary" />
                <span className="truncate">{t('pos.title', { branch, register: session.register })}</span>
            </h1>
            <Link to="/" className={`${pill} bg-subtle text-fg-secondary hover:text-fg-primary`}>
                <SquaresFourIcon size={14} aria-hidden />
                <span className="sr-only sm:not-sr-only">{t('pos.dashboard')}</span>
            </Link>

            <span className="flex-1" />

            <span role="status" className={`${pill} ${online ? 'bg-success-subtle text-feedback-success' : 'bg-danger-subtle text-feedback-danger'}`}>
                {online ? <CheckCircleIcon size={14} aria-hidden /> : <CloudSlashIcon size={14} aria-hidden />}
                <span className={online ? 'sr-only md:not-sr-only' : ''}>{online ? t('pos.online') : t('pos.offline')}</span>
            </span>
            <span className={`${pill} hidden bg-subtle text-fg-secondary lg:inline-flex`}>
                <ClockIcon size={14} aria-hidden />
                {t('pos.drawer', {
                    time: dhakaTime(session.opened_at, locale),
                    float: takaText(session.opening_float),
                })}
            </span>
            {heldCount > 0 && (
                <button type="button" onClick={onHeld} aria-keyshortcuts="F8" className={`${pill} bg-warning-subtle text-feedback-warning hover:brightness-95`}>
                    <StackIcon size={14} aria-hidden />
                    {t('pos.held', { count: heldCount })}
                </button>
            )}
            <button type="button" onClick={onCloseDrawer} className={`${pill} bg-subtle text-fg-secondary hover:text-fg-primary`}>
                <CashRegisterIcon size={14} aria-hidden />
                <span className="sr-only md:not-sr-only">{t('pos.closeDrawer')}</span>
            </button>
            <span
                title={t('pos.cashier', { name: session.cashier.name })}
                aria-label={t('pos.cashier', { name: session.cashier.name })}
                role="img"
                className="grid size-7 shrink-0 place-items-center rounded-full bg-brand-subtle t-admin-caption-strong text-fg-brand"
            >
                {initials(session.cashier.name)}
            </span>
        </header>
    );
}
