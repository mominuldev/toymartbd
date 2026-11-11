import { Icon } from '@/components/icons/Icon';
import { getMessages, localizedPath, type Locale } from '@/lib/i18n';

interface Props {
    locale: Locale;
    /** Unique per instance: the header renders one bar for mobile and one for desktop. */
    id: string;
    defaultValue?: string;
}

// Storefront spec §A6.1 — the bar only. A plain GET form to /search?q=, so it works without
// JavaScript and the results URL is shareable.
// TODO(P4-STO-04): the suggestion dropdown (§A6.2: 250 ms debounce, 2-character minimum,
// products → categories → brands, aria-activedescendant) needs a storefront search-suggest
// endpoint that does not exist yet. Add it as a client island on top of this form.
export function SearchBar({ locale, id, defaultValue }: Props) {
    const t = getMessages(locale);
    return (
        <form action={localizedPath(locale, '/search')} method="get" role="search" className="w-full">
            <label htmlFor={id} className="sr-only">
                {t.search.label}
            </label>
            <div className="flex h-control-md items-center gap-3 rounded-full border border-line-default bg-surface pl-4 focus-within:border-line-focus focus-within:shadow-focus">
                <Icon name="magnifyingGlass" className="size-icon-md text-fg-tertiary" />
                <input
                    id={id}
                    name="q"
                    type="search"
                    defaultValue={defaultValue}
                    placeholder={t.search.placeholder}
                    autoComplete="off"
                    enterKeyHint="search"
                    className="t-body h-full min-w-0 flex-1 bg-transparent text-fg-primary placeholder:text-fg-tertiary focus:outline-none focus-visible:shadow-none"
                />
                <button
                    type="submit"
                    className="t-button inline-flex h-full min-w-touch-min shrink-0 items-center justify-center rounded-full bg-brand px-4 text-fg-on-brand"
                >
                    {t.search.submit}
                </button>
            </div>
        </form>
    );
}
