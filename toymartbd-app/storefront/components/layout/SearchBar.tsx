import { Icon } from '@/components/icons/Icon';
import { getMessages, localizedPath, type Locale } from '@/lib/i18n';

interface Props {
    locale: Locale;
    /** Unique per instance: the header renders one bar for mobile and one for desktop. */
    id: string;
    defaultValue?: string;
}

// Figma SearchBar 111:853 (storefront spec §A6.1) — the bar only: a white pill with a 1.5px
// soft-pink rim, a coral search glyph and no visible button; the 2px teal focus ring sits
// outside the pill so the bar never grows. A plain GET form to /search?q=, submitted with Enter
// (implicit submission), so it works without JavaScript and the results URL is shareable.
// The placeholder ellipsises rather than wrapping or clipping mid-glyph (320px Bangla).
// TODO(P4-STO-04): the suggestion dropdown (§A6.2: 250 ms debounce, 2-character minimum,
// products → categories → brands, aria-activedescendant) and the HasQuery clear button need a
// storefront search-suggest endpoint that does not exist yet. Add them as a client island here.
export function SearchBar({ locale, id, defaultValue }: Props) {
    const t = getMessages(locale);
    return (
        <form action={localizedPath(locale, '/search')} method="get" role="search" className="w-full">
            <label htmlFor={id} className="sr-only">
                {t.search.label}
            </label>
            <div className="flex h-control-md items-center gap-2 rounded-full border-(length:--tm-border-width-control) border-line-brand-subtle bg-surface pr-1 pl-4 focus-within:shadow-focus">
                <Icon name="magnifyingGlass" className="size-icon-md text-fg-brand" />
                <input
                    id={id}
                    name="q"
                    type="search"
                    defaultValue={defaultValue}
                    placeholder={t.search.placeholder}
                    autoComplete="off"
                    enterKeyHint="search"
                    className="t-body h-full min-w-0 flex-1 bg-transparent text-ellipsis text-fg-primary placeholder:text-fg-tertiary focus:outline-none focus-visible:shadow-none"
                />
            </div>
        </form>
    );
}
