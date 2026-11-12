import { Icon } from '@/components/icons/Icon';
import { getMessages, localizedPath, type Locale } from '@/lib/i18n';
import { SearchField } from './SearchField';

interface Props {
    locale: Locale;
    /** Unique per instance: the header renders one bar for mobile and one for desktop. */
    id: string;
    defaultValue?: string;
}

// Figma SearchBar 111:853 (storefront spec §A6.1) — a white pill with a 1.5px soft-pink rim, a
// coral search glyph and no visible button; the 2px teal focus ring sits outside the pill so the
// bar never grows. A plain GET form to /search?q=, submitted with Enter (implicit submission), so
// it works without JavaScript and the results URL is shareable. The placeholder ellipsises rather
// than wrapping or clipping mid-glyph (320px Bangla). SearchField is the client island that adds
// the HasQuery clear button and the suggestion dropdown (§A6.2, Figma 115:887) once hydrated.
export function SearchBar({ locale, id, defaultValue }: Props) {
    const t = getMessages(locale);
    const s = t.search;
    return (
        <form action={localizedPath(locale, '/search')} method="get" role="search" className="w-full">
            <label htmlFor={id} className="sr-only">
                {s.label}
            </label>
            <div className="flex h-control-md items-center gap-2 rounded-full border-(length:--tm-border-width-control) border-line-brand-subtle bg-surface pr-1 pl-4 focus-within:shadow-focus">
                <Icon name="magnifyingGlass" className="size-icon-md text-fg-brand" />
                <SearchField
                    locale={locale}
                    id={id}
                    defaultValue={defaultValue}
                    ageCopy={{ ageMonths: t.listing.ageMonths, ageMonthsPlus: t.listing.ageMonthsPlus, ageYears: t.listing.ageYears, ageYearsPlus: t.listing.ageYearsPlus, ageUpTo: t.listing.ageUpTo }}
                    copy={{
                        label: s.label,
                        placeholder: s.placeholder,
                        clear: s.clear,
                        suggestProducts: s.suggestProducts,
                        suggestCategories: s.suggestCategories,
                        suggestBrands: s.suggestBrands,
                        suggestSeeAll: s.suggestSeeAll,
                        suggestNoMatch: s.suggestNoMatch,
                        suggestCount: s.suggestCount,
                        recentTitle: s.recentTitle,
                        recentClear: s.recentClear,
                        recentRemove: s.recentRemove,
                    }}
                />
            </div>
        </form>
    );
}
