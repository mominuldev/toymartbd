import { getMessages, localizedPath, type Locale } from '@/lib/i18n';
import { mobileMenu, type CmsMenuItem } from '@/lib/content/types';
import type { MenuLink } from '@/lib/category-tree';

interface Props {
    locale: Locale;
    items: CmsMenuItem[];
}

function attrs(locale: Locale, link: MenuLink) {
    return {
        href: link.external ? link.path : localizedPath(locale, link.path),
        ...(link.newTab ? { target: '_blank', rel: 'noopener noreferrer' } : link.external ? { rel: 'noopener' } : {}),
    };
}

// The published mobile menu (CMS location `mobile`), shown on /categories — where the header's
// menu button leads below 1024. Level 1 as rows, level 2 as indented links. Absent menu → nothing,
// and the page shows only the category tree as before.
export function MobileMenuLinks({ locale, items }: Props) {
    const t = getMessages(locale);
    const rows = mobileMenu(items, locale);
    if (rows.length === 0) return null;
    const bn = (lang?: 'en') => (locale === 'bn' && !lang ? 'lang-bn' : '');

    return (
        <nav aria-label={t.content.menu} className="mt-6 lg:hidden">
            <ul className="flex flex-col divide-y divide-line-default rounded-lg bg-surface shadow-e1">
                {rows.map((row, i) => (
                    <li key={i} className="flex flex-col px-4 py-1">
                        {row.link ? (
                            <a {...attrs(locale, row.link)} lang={row.label.lang} className={`t-body-strong flex min-h-touch-min items-center text-fg-primary ${bn(row.label.lang)}`}>
                                {row.label.text}
                            </a>
                        ) : (
                            <p lang={row.label.lang} className={`t-overline flex min-h-touch-min items-center text-fg-tertiary ${bn(row.label.lang)}`}>
                                {row.label.text}
                            </p>
                        )}
                        {row.children.length > 0 ? (
                            <ul className="flex flex-col pb-2 ps-3">
                                {row.children.map((link) => (
                                    <li key={link.path}>
                                        <a {...attrs(locale, link)} lang={link.lang} className={`t-body flex min-h-touch-min items-center text-fg-secondary ${bn(link.lang)}`}>
                                            {link.label}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                    </li>
                ))}
            </ul>
        </nav>
    );
}
