import { Icon } from '@/components/icons/Icon';
import { format, type Locale, type Messages } from '@/lib/i18n';
import { chipLabel } from '@/lib/listing/chips';
import type { Facets } from '@/lib/listing/response';
import { activeFilters, cleared, hrefFor, withoutValue, type ListingState } from '@/lib/listing/state';

// ActiveFilterChips 116:1359 (storefront spec §A7.5): one removable Chip per active filter, then
// "Clear all". Each × is a link to the same listing without that value, so removal works without
// JavaScript and is undone by the back button.

interface Props {
    href: string;
    state: ListingState;
    facets: Facets;
    locale: Locale;
    t: Messages['listing'];
}

export function ActiveFilterChips({ href, state, facets, locale, t }: Props) {
    const filters = activeFilters(state);
    if (filters.length === 0) return null;
    return (
        <nav aria-label={t.activeFilters}>
            <ul className="flex flex-wrap items-center gap-2">
                {filters.map(({ key, value }) => {
                    const label = chipLabel(key, value, facets, locale, t);
                    return (
                        <li key={`${key}:${value}`} className="t-chip flex h-control-sm min-w-14 items-center gap-1 rounded-full border border-brand bg-brand-subtle pr-1 pl-3 text-fg-primary">
                            <span lang={label.lang}>{label.text}</span>
                            <a
                                href={hrefFor(href, withoutValue(state, key, value))}
                                aria-label={format(t.removeFilter, { label: label.text })}
                                className="relative flex size-7 items-center justify-center rounded-full hover:bg-surface before:absolute before:size-touch-min before:content-['']"
                            >
                                <Icon name="x" className="size-icon-sm" />
                            </a>
                        </li>
                    );
                })}
                <li>
                    <a href={hrefFor(href, cleared(state))} className="t-body-sm-strong flex h-control-sm items-center px-2 text-fg-brand">
                        {t.clearAll}
                    </a>
                </li>
            </ul>
        </nav>
    );
}
