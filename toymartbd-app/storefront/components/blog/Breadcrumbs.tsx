import { Icon } from '@/components/icons/Icon';
import { localizedPath, type Locale } from '@/lib/i18n';

// The listing breadcrumb's markup (ListingView PageHead), for blog pages. The last crumb is the
// current page (path null).

export interface BlogCrumb {
    label: string;
    path: string | null;
    lang?: 'en';
}

export function Breadcrumbs({ locale, crumbs, label, current }: { locale: Locale; crumbs: BlogCrumb[]; label: string; current: string }) {
    return (
        <nav aria-label={label} className="overflow-x-auto overflow-y-hidden">
            <ol className="flex min-h-6 items-center gap-2 whitespace-nowrap">
                {crumbs.map((crumb, i) => (
                    <li key={`${crumb.path ?? 'current'}-${i}`} className="flex items-center gap-2">
                        {i > 0 ? <Icon name="caretRight" className="size-3.5 text-fg-tertiary" /> : null}
                        {crumb.path === null ? (
                            <a href={current} aria-current="page" lang={crumb.lang} className="t-body-sm-strong max-w-60 truncate text-fg-secondary">
                                {crumb.label}
                            </a>
                        ) : (
                            <a href={localizedPath(locale, crumb.path)} lang={crumb.lang} className="t-body-sm text-fg-link underline-offset-2 hover:underline">
                                {crumb.label}
                            </a>
                        )}
                    </li>
                ))}
            </ol>
        </nav>
    );
}
