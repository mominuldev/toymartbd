import { Fragment, type ReactNode } from 'react';
import { Link } from 'react-router';
import { useI18n } from '@/i18n/I18nProvider';

export type Crumb = { label: string; to?: string };

type ContentHeaderProps = {
    title: string;
    /** "Catalog / Products / Categories" — the last crumb is usually the page itself. */
    breadcrumb?: Crumb[];
    subtitle?: ReactNode;
    /** AdminButtons (md), 12 apart; the primary goes last. */
    actions?: ReactNode;
    /** Inline next to the title, 12 apart (Order detail 206:1475: the OrderStatusBadge). */
    titleAddon?: ReactNode;
};

/**
 * Figma ContentHeader 187:530 — breadcrumb (Admin/caption, text/tertiary), page title
 * (Admin/page-title), subtitle (Admin/body, text/secondary), 4 apart; actions on the right.
 */
export function ContentHeader({ title, breadcrumb, subtitle, actions, titleAddon }: ContentHeaderProps) {
    const { t } = useI18n();

    return (
        <div className="flex flex-wrap items-center gap-4">
            <div className="flex min-w-0 flex-1 basis-64 flex-col gap-1">
                {breadcrumb && breadcrumb.length > 0 && (
                    <nav aria-label={t('shell.breadcrumb')}>
                        <ol className="flex flex-wrap items-center gap-1 t-admin-caption text-fg-tertiary">
                            {breadcrumb.map((crumb, index) => (
                                <Fragment key={`${crumb.label}-${index}`}>
                                    {index > 0 && (
                                        <li aria-hidden className="select-none">
                                            /
                                        </li>
                                    )}
                                    <li>
                                        {crumb.to ? (
                                            <Link to={crumb.to} className="hover:text-fg-primary hover:underline">
                                                {crumb.label}
                                            </Link>
                                        ) : (
                                            <span aria-current={index === breadcrumb.length - 1 ? 'page' : undefined}>{crumb.label}</span>
                                        )}
                                    </li>
                                </Fragment>
                            ))}
                        </ol>
                    </nav>
                )}
                {titleAddon ? (
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <h1 className="t-admin-page-title text-fg-primary">{title}</h1>
                        {titleAddon}
                    </div>
                ) : (
                    <h1 className="t-admin-page-title text-fg-primary">{title}</h1>
                )}
                {subtitle && <p className="t-admin-body text-fg-secondary">{subtitle}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-3">{actions}</div>}
        </div>
    );
}
